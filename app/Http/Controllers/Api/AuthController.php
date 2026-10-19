<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\ProgramMember;
use App\Models\TeamJoinCode;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use App\Support\LoginChallenge;
use App\Support\ProfilePhoto;
use App\Support\RoleSlug;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AuthController extends Controller
{
    public function login(Request $request)
    {
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    /** @var User|null $user */
    $user = User::where('email', $validated['email'])->first();

    // Account does not exist
    if (!$user) {
        throw ValidationException::withMessages([
            'email' => ['No existing account found for that email.'],
        ]);
    }

    // IP-based login throttle: limit attempts per IP per minute
    $ip = $request->ip() ?? 'unknown';
    $ipKey = 'login_ip_attempts:' . $ip;
    $ipCount = (int) Cache::get($ipKey, 0);
    $ipLimit = $this->getLoginIpLimitPerMinute();
    if ($ipCount >= $ipLimit) {
        throw ValidationException::withMessages([
            'email' => ['Too many login attempts from your IP address. Please wait and try again.'],
        ]);
    }
    Cache::put($ipKey, $ipCount + 1, 60);

    // Check password directly
    if (!Hash::check($validated['password'], $user->password)) {
        throw ValidationException::withMessages([
            'password' => ['Wrong credentials.'],
        ]);
    }

    // Optional: prevent inactive/locked accounts from logging in
    if (isset($user->status) && $user->status === 'inactive') {
        throw ValidationException::withMessages([
            'email' => ['This account is inactive.'],
        ]);
    }

    if (isset($user->status) && $user->status === 'locked') {
        throw ValidationException::withMessages([
            'email' => ['This account is locked. Please contact an administrator.'],
        ]);
    }

    if ($this->requiresTwoFactor($user)) {
        if ($lockout = LoginChallenge::lockoutMessage((int) $user->id)) {
            throw ValidationException::withMessages([
                'email' => [$lockout],
            ]);
        }

        try {
            $challenge = LoginChallenge::issue($user);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'We could not send the verification code. Try again in a few minutes.',
            ], 503);
        }

        $request->attributes->set('audit_event', '2fa_challenge_sent');

        return response()->json([
            'success' => true,
            'message' => 'A verification code was sent to your email.',
            'data' => [
                'challenge_token' => $challenge['challenge_token'],
                'email' => $user->email,
                'expires_in' => $challenge['expires_in'],
            ],
        ], 200);
    }

    $request->attributes->set('audit_event', 'login');

    return $this->issueAuthenticatedSession($user);
}

    public function verifyTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $challenge = LoginChallenge::get($validated['challenge_token']);

        if (! $challenge) {
            throw ValidationException::withMessages([
                'challenge_token' => ['The login verification token is invalid or has expired. Please try signing in again.'],
            ]);
        }

        $attempts = (int) ($challenge['attempts'] ?? 0);
        if ($attempts >= (int) config('adams.two_factor_max_attempts', 5)) {
            LoginChallenge::forget($validated['challenge_token']);
            LoginChallenge::lock((int) $challenge['user_id']);
            $request->attributes->set('audit_event', '2fa_lockout');
            throw ValidationException::withMessages([
                'code' => [LoginChallenge::lockoutMessage((int) $challenge['user_id']) ?? 'Too many invalid codes. Please sign in again later.'],
            ]);
        }

        if (! LoginChallenge::matches($challenge, $validated['code'])) {
            $challenge['attempts'] = $attempts + 1;
            if ($challenge['attempts'] >= (int) config('adams.two_factor_max_attempts', 5)) {
                LoginChallenge::forget($validated['challenge_token']);
                LoginChallenge::lock((int) $challenge['user_id']);
                $request->attributes->set('audit_event', '2fa_lockout');
                throw ValidationException::withMessages([
                    'code' => [LoginChallenge::lockoutMessage((int) $challenge['user_id']) ?? 'Too many invalid codes. Please sign in again later.'],
                ]);
            }

            LoginChallenge::put($validated['challenge_token'], $challenge);
            $request->attributes->set('audit_event', '2fa_failed');

            throw ValidationException::withMessages([
                'code' => ['The verification code is incorrect. Please try again.'],
            ]);
        }

        $user = User::find($challenge['user_id']);
        LoginChallenge::forget($validated['challenge_token']);

        if (! $user) {
            throw ValidationException::withMessages([
                'challenge_token' => ['The login verification token is invalid. Please try again.'],
            ]);
        }

        if (isset($user->status) && $user->status === 'inactive') {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        if (isset($user->status) && $user->status === 'locked') {
            throw ValidationException::withMessages([
                'email' => ['This account is locked. Please contact an administrator.'],
            ]);
        }

        $request->attributes->set('audit_event', '2fa_success');

        return $this->issueAuthenticatedSession($user);
    }

    private function issueAuthenticatedSession(User $user)
    {
        $user->forceFill(['last_login_at' => now()])->save();
        request()->setUserResolver(static fn () => $user);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Authenticated successfully.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ], 200);
    }

    private function requiresTwoFactor(User $user): bool
    {
        if (! config('adams.two_factor_enabled')) {
            return false;
        }

        // Break-glass: superadmin always skips login OTP.
        if ($user->isSuperAdmin()) {
            return false;
        }

        $email = strtolower(trim((string) $user->email));
        $exempt = (array) config('adams.two_factor_exempt_emails', []);
        if ($email !== '' && in_array($email, $exempt, true)) {
            return false;
        }

        // Prefer an explicit role allow-list when configured.
        $required = array_map(
            fn (string $role) => RoleSlug::canonicalize($role) ?? $role,
            (array) config('adams.two_factor_roles', [])
        );

        if ($required === []) {
            return true;
        }

        foreach ($user->getRoleNames() as $name) {
            $slug = RoleSlug::canonicalize($name);
            if ($slug && in_array($slug, $required, true)) {
                return true;
            }
        }

        // New / unassigned accounts still get email OTP on login.
        return $user->getRoleNames()->isEmpty();
    }

    private function getLoginIpLimitPerMinute(): int
    {
        return 12;
    }

    public function resendTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
        ]);

        $challenge = LoginChallenge::get($validated['challenge_token']);

        if (! $challenge) {
            return response()->json([
                'success' => false,
                'message' => 'The login verification token is invalid or has expired. Please sign in again.',
            ], 422);
        }

        $resendCount = (int) ($challenge['resend_count'] ?? 0);
        $maxResends = (int) config('adams.two_factor_max_resends', 3);
        if ($resendCount >= $maxResends) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached the maximum number of resends. Please sign in again.',
            ], 429);
        }

        $now = now()->getTimestamp();
        $lastResend = isset($challenge['last_resend_at']) ? (int) $challenge['last_resend_at'] : 0;
        if ($lastResend > $now) {
            $lastResend = 0;
        }

        $cooldown = (int) config('adams.two_factor_resend_cooldown_seconds', 30);
        if ($resendCount > 0 && $lastResend && ($now - $lastResend) < $cooldown) {
            $remaining = $cooldown - ($now - $lastResend);

            return response()->json([
                'success' => false,
                'message' => 'Please wait ' . $remaining . ' seconds before requesting another code.',
            ], 429);
        }

        $ip = $request->ip() ?? 'unknown';
        $ipKey = 'resend_2fa_ip:' . $ip;
        $ipCount = (int) Cache::get($ipKey, 0);
        if ($ipCount >= 6) {
            return response()->json([
                'success' => false,
                'message' => 'Too many resend requests from your IP address. Please wait and try again.',
            ], 429);
        }

        $user = User::find($challenge['user_id']);
        if (! $user) {
            LoginChallenge::forget($validated['challenge_token']);

            return response()->json([
                'success' => false,
                'message' => 'The login verification token is invalid. Please sign in again.',
            ], 422);
        }

        $previousHash = $challenge['code_hash'];
        $code = (string) random_int(100000, 999999);
        $challenge['code_hash'] = hash('sha256', $code);
        $challenge['attempts'] = 0;
        $challenge['resend_count'] = $resendCount + 1;
        $challenge['last_resend_at'] = $now;
        LoginChallenge::put($validated['challenge_token'], $challenge);

        try {
            $user->notify(new LoginVerificationCodeNotification($code, (int) max(1, (int) ceil(LoginChallenge::ttl() / 60))));
        } catch (\Throwable $exception) {
            $challenge['code_hash'] = $previousHash;
            $challenge['resend_count'] = $resendCount;
            LoginChallenge::put($validated['challenge_token'], $challenge);
            Log::error('Login verification resend failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not send the verification code. Try again in a few minutes.',
            ], 503);
        }

        Cache::put($ipKey, $ipCount + 1, 60);
        $request->attributes->set('audit_event', '2fa_code_resent');

        return response()->json([
            'success' => true,
            'message' => 'Verification code resent.',
            'expires_in' => LoginChallenge::ttl(),
            'resend_count' => $challenge['resend_count'],
        ], 200);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $status = Password::sendResetLink($validated);
        } catch (\Throwable $exception) {
            Log::error('Password reset email failed', [
                'email' => $validated['email'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not send the password reset email. Try again in a few minutes.',
            ], 503);
        }

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email address.',
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => __($status),
        ], 422);
    }

    public function checkSmtpSettings(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        if ($this->isMailConfigInvalid()) {
            return response()->json([
                'success' => false,
                'message' => 'Mail is not configured correctly. On Render free tier use MAIL_MAILER=brevo with BREVO_API_KEY (and a verified sender). Locally you can use MAIL_MAILER=smtp with Gmail.',
            ], 422);
        }

        try {
            Mail::raw(
                'This is a test email from ADAMS to confirm that mail delivery is configured correctly. If you received this email, outbound mail is working.',
                function ($message) use ($validated) {
                    $message->to($validated['email'])
                        ->subject('ADAMS Mail Configuration Test');
                }
            );

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully. Please check your inbox or spam folder.',
            ], 200);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Mail check failed. Please verify your mail settings and credentials.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    private function isMailConfigInvalid(): bool
    {
        if (app()?->runningUnitTests()) {
            return false;
        }

        $defaultMailer = (string) config('mail.default');

        if ($defaultMailer === 'brevo') {
            return trim((string) config('services.brevo.key', '')) === '';
        }

        if ($defaultMailer === 'resend') {
            $key = (string) config('services.resend.key', '');

            return $key === '' || str_starts_with($key, 're_') === false;
        }

        if ($defaultMailer !== 'smtp') {
            return true;
        }

        $username = (string) config('mail.mailers.smtp.username');
        $password = (string) config('mail.mailers.smtp.password');

        return $username === ''
            || $password === ''
            || ! str_contains($username, '@')
            || str_contains($username, 'your-gmail')
            || str_contains($password, 'your-gmail')
            || str_contains($password, 'password');
    }

    private function assignDefaultPermissionsToRole(Role $role, string $roleNameLower): void
    {
        // Define default permissions for each role
        $rolePermissions = [
            'faculty' => [
                'view dashboard',
                'upload documents',
                'submit reviews',
            ],
            'dean' => [
                'view dashboard',
                'access-college-dashboard',
                'manage reviews',
                'approve reviews',
                'review reports',
                'manage teams',
                'manage documents',
            ],
            'program chair' => [
                'view dashboard',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'review reports',
                'manage reviews',
                'request revisions',
            ],
            'program-chair' => [
                'view dashboard',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'review reports',
                'manage reviews',
                'request revisions',
            ],
            'area in-charge' => [
                'view dashboard',
                'manage reviews',
                'request revisions',
                'review reports',
            ],
            'area-in-charge' => [
                'view dashboard',
                'manage reviews',
                'request revisions',
                'review reports',
            ],
            'qa' => [
                'view dashboard',
                'review reports',
                'view audit logs',
            ],
            'vpaa' => [
                'view dashboard',
                'approve reviews',
                'review reports',
                'view audit logs',
            ],
            'vpaa/di' => [
                'view dashboard',
                'approve reviews',
                'review reports',
                'view audit logs',
            ],
            'super administrator' => [
                'view dashboard',
                'manage users',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'manage documents',
                'submit reviews',
                'manage reviews',
                'approve reviews',
                'request revisions',
                'review reports',
                'view audit logs',
                'view login history',
            ],
            'super admin' => [
                'view dashboard',
                'manage users',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'manage documents',
                'submit reviews',
                'manage reviews',
                'approve reviews',
                'request revisions',
                'review reports',
                'view audit logs',
                'view login history',
            ],
            'superadmin' => [
                'view dashboard',
                'manage users',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'manage documents',
                'submit reviews',
                'manage reviews',
                'approve reviews',
                'request revisions',
                'review reports',
                'view audit logs',
                'view login history',
            ],
        ];

        $permissionNames = $rolePermissions[$roleNameLower] ?? $rolePermissions['faculty'];

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            $role->givePermissionTo($permission);
        }
    }

    public function resendVerificationEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => true,
                'message' => 'If your account exists and is not already verified, we have sent a verification link to your email.',
            ], 200);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'This email address is already verified. Please sign in.',
            ], 200);
        }

        $cooldownKey = 'email_verification_resend:' . $user->id;
        $lastSent = (int) Cache::get($cooldownKey, 0);
        $cooldown = (int) config('adams.email_resend_cooldown_seconds', 60);
        $elapsed = now()->getTimestamp() - $lastSent;
        if ($lastSent && $elapsed < $cooldown) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait ' . ($cooldown - $elapsed) . ' seconds before requesting another verification email.',
            ], 429);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            Log::error('Verification email failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not send the verification email. Try again in a few minutes.',
            ], 503);
        }

        Cache::put($cooldownKey, now()->getTimestamp(), $cooldown);
        $request->attributes->set('audit_event', 'email_verification_sent');

        return response()->json([
            'success' => true,
            'message' => 'Verification email resent. Please check your inbox and spam folder.',
        ], 200);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $frontendUrl = env('FRONTEND_URL', config('app.frontend_url', config('app.url')));
        $user = User::find($id);
        $baseRedirect = rtrim($frontendUrl, '/') . '/email-verified?status=invalid';

        if (! $request->hasValidSignature()) {
            $redirectUrl = $baseRedirect;
            if ($user) {
                $redirectUrl .= '&email=' . urlencode($user->email);
            }

            return redirect()->away($redirectUrl);
        }

        if (! $user) {
            return redirect()->away($baseRedirect);
        }

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            $redirectUrl = $baseRedirect . '&email=' . urlencode($user->email);
            return redirect()->away($redirectUrl);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->away(rtrim($frontendUrl, '/') . '/email-verified?status=already_verified');
        }

        $user->markEmailAsVerified();
        $request->attributes->set('audit_event', 'email_verified');

        return redirect()->away(rtrim($frontendUrl, '/') . '/email-verified?status=success');
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Password has been reset successfully.',
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => __($status),
        ], 422);
    }

    public function register(Request $request)
    {
        $creator = $request->user('sanctum') ?? $request->user('api') ?? $request->user();
        $isSuperAdmin = $creator && (
            $creator->hasRole('Super Administrator') ||
            $creator->hasRole('Super Admin') ||
            $creator->hasRole('super administrator') ||
            $creator->hasRole('superadmin')
        );

        $profilePhotoRules = ['nullable', 'image', 'max:10240'];
        if ($isSuperAdmin) {
            $profilePhotoRules = ['required', 'image', 'max:10240'];
        }

        $minimumAgeDate = now()->subYears(21)->toDateString();
        $institutionalDomain = (string) config('adams.institutional_email_domain', 'isu.edu.ph');
        $emailRules = ['required', 'email', 'max:255', 'unique:users,email'];
        if (! $isSuperAdmin) {
            $emailRules[] = 'regex:/^[A-Za-z0-9._%+\-]+@'.preg_quote($institutionalDomain, '/').'$/i';
        }

        try {
            $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required_without:name', 'string', 'max:255'],
            'last_name' => ['required_without:name', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => $emailRules,
            'password' => $isSuperAdmin
                ? ['required', 'string', 'min:8']
                : ['required', 'string', 'min:8', 'regex:/^(?=.*[A-Z])(?=.*\d).+$/'],
            'password_confirmation' => ['nullable', 'string'],
            'phone' => $isSuperAdmin
                ? ['nullable', 'string', 'max:255']
                : ['required', 'regex:/^\d{1,12}$/'],
            'birthdate' => $isSuperAdmin
                ? ['nullable', 'date']
                : ['required', 'date', 'before_or_equal:'.$minimumAgeDate],
            'sex' => $isSuperAdmin
                ? ['nullable', 'in:male,female']
                : ['required', 'in:male,female'],
            'privacy_accepted' => $isSuperAdmin ? ['nullable'] : ['accepted'],
            'role' => ['sometimes', 'string'],
            'profile_photo' => $profilePhotoRules,
        ], [
            'email.regex' => 'Use your institutional email (@'.$institutionalDomain.').',
            'password.min' => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must include at least one uppercase letter and one number.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Phone number must contain numbers only, up to 12 digits.',
            'birthdate.required' => 'Birth date is required.',
            'birthdate.date' => 'Enter a valid birth date.',
            'birthdate.before_or_equal' => 'You must be at least 21 years old to register.',
            'sex.required' => 'Sex is required.',
            'sex.in' => 'Select Male or Female.',
            'privacy_accepted.accepted' => 'You must agree to the Data Privacy notice before registering.',
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['profile_photo']) && in_array('The profile photo field is required.', $errors['profile_photo'], true)) {
                $errors['profile_photo'] = ['A profile photo is required.'];
            }
            if (isset($errors['profile_photo']) && (
                in_array('The profile photo must be an image.', $errors['profile_photo'], true) ||
                in_array('The profile photo field must be an image.', $errors['profile_photo'], true)
            )) {
                $errors['profile_photo'] = ['The profile photo must be an image file.'];
            }
            Log::debug('Registration validation failed', ['errors' => $errors, 'input' => $request->all()]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        if ($request->filled('password_confirmation') && $request->input('password') !== $request->input('password_confirmation')) {
            throw ValidationException::withMessages([
                'password' => ['The password field confirmation does not match.'],
            ]);
        }

        // Determine name parts
        if (! isset($validated['first_name']) || ! isset($validated['last_name'])) {
            $nameParts = preg_split('/\s+/', trim($validated['name']));
            $firstName = $nameParts[0] ?? '';
            $lastName = count($nameParts) > 1 ? array_pop($nameParts) : $firstName;
            $middleName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1, -1)) : null;
        } else {
            $firstName = $validated['first_name'];
            $lastName = $validated['last_name'];
            $middleName = $validated['middle_name'] ?? null;
        }

        $profilePhotoFile = $request->hasFile('profile_photo') ? $request->file('profile_photo') : null;
        $profilePhotoPath = null;

        // Determine creator and whether they're a super admin
        $creator = $request->user('sanctum') ?? $request->user('api') ?? $request->user();
        $isSuperAdmin = $creator && (
            $creator->hasRole('Super Administrator') ||
            $creator->hasRole('Super Admin') ||
            $creator->hasRole('super administrator') ||
            $creator->hasRole('superadmin')
        );

        // Public self-registration is allowed, but authenticated non-super-admins may not create users.
        if ($creator && ! $isSuperAdmin) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        DB::beginTransaction();

        try {
            // Create the user record
            $user = User::create([
                'name' => trim($validated['name'] ?? trim($firstName . ($middleName ? ' ' . $middleName : '') . ' ' . $lastName)),
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone_number' => $validated['phone'] ?? null,
                'birth_date' => $validated['birthdate'] ?? null,
                'sex' => $validated['sex'] ?? null,
            ]);
            if ($profilePhotoFile) {
                ProfilePhoto::store($user, $profilePhotoFile);
                $profilePhotoPath = $user->profile_photo;
            }
            if ($isSuperAdmin) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            // Role assignment rules:
            // - Only authenticated Super Administrators may create accounts with an elevated role.
            // - Otherwise default to the standard faculty role.
            $creator = $request->user('sanctum') ?? $request->user('api') ?? $request->user();
            $role = 'faculty';
            $isSuperAdmin = $creator && (
                $creator->hasRole('Super Administrator') ||
                    $creator->hasRole('Super Admin') ||
                    $creator->hasRole('super administrator') ||
                    $creator->hasRole('superadmin')
            );

            if (! empty($validated['role']) && $isSuperAdmin) {
                $role = $validated['role'];
            }

            $roleName = trim(strtolower(str_replace(['_', '-'], ' ', $role)));
            $roleModel = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user->assignRole($roleModel);
            
            // Assign default permissions to the role if it's newly created
            $this->assignDefaultPermissionsToRole($roleModel, $roleName);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            if (! empty($profilePhotoPath)) {
                Storage::disk('public')->delete($profilePhotoPath);
            }
            throw $e;
        }

        // Role assignment rules:
        // - Only authenticated Super Administrators may create accounts with an elevated role.
        // - Otherwise default to the standard faculty role.
        $creator = $request->user('sanctum') ?? $request->user('api') ?? $request->user();
        $role = 'faculty';
        $isSuperAdmin = $creator && (
            $creator->hasRole('Super Administrator') ||
                $creator->hasRole('Super Admin') ||
                $creator->hasRole('super administrator') ||
                $creator->hasRole('superadmin')
        );

        if (! empty($validated['role']) && $isSuperAdmin) {
            $role = $validated['role'];
        }

        $roleName = trim(strtolower(str_replace(['_', '-'], ' ', $role)));
        $roleModel = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        // Ensure permissions are assigned (in case role was just created)
        $this->assignDefaultPermissionsToRole($roleModel, $roleName);

        $token = null;
        if ($creator && $isSuperAdmin) {
            $token = $user->createToken('api-token')->plainTextToken;
        }

        $responseData = ['user' => new UserResource($user)];
        if ($token) {
            $responseData['token'] = $token;
        }

        $message = 'Registration successful. You can sign in now.';
        if (! $isSuperAdmin && config('adams.email_verification_enabled')) {
            try {
                $user->sendEmailVerificationNotification();
                $request->attributes->set('audit_event', 'email_verification_sent');
                $message = 'Registration successful. Check your email to verify your account, then sign in.';
            } catch (\Throwable $exception) {
                Log::error('Registration verification email failed', [
                    'user_id' => $user->id,
                    'error' => $exception->getMessage(),
                ]);
                $message = 'Registration successful, but we could not send the verification email. Sign in and use the resend button.';
            }
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $responseData,
        ], 201);
    }

    public function updateProfilePhoto(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $request->validate([
            'profile_photo' => ['required', 'image', 'max:10240'],
        ]);

        ProfilePhoto::store($user, $request->file('profile_photo'));

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated successfully.',
            'data' => [
                'user' => new UserResource($user->fresh()),
            ],
        ], 200);
    }

    public function profilePhoto(User $user)
    {
        return ProfilePhoto::response($user);
    }

    public function logout(Request $request)
    {
        $user = $request->user() ?? $request->user('api');
        if ($user) {
            $tokenModel = $user->currentAccessToken();
            $tokenId = $tokenModel instanceof \Laravel\Sanctum\PersonalAccessToken ? $tokenModel->id : null;
            Log::debug('logout called', ['bearer' => $request->bearerToken(), 'user_id' => $user->id, 'current_token_id' => $tokenId]);
            // Revoke the token used for this request
            try {
                $deleted = false;
                $before = $user->tokens()->count();
                Log::debug('logout tokens before', ['count' => $before]);
                if ($tokenModel instanceof \Laravel\Sanctum\PersonalAccessToken) {
                    $deleted = (bool) $tokenModel->delete();
                }

                if (! $deleted) {
                    $user->tokens()->delete();
                }
                $after = $user->tokens()->count();
                // Also explicitly remove token by ID parsed from bearer (sanctum tokens use "id|plain" format)
                try {
                    $bearer = $request->bearerToken();
                    if (is_string($bearer) && str_contains($bearer, '|')) {
                        [$tokenId] = explode('|', $bearer, 2);
                        if (is_numeric($tokenId)) {
                            \Spatie\Permission\Models\Role::unguard(); // noop but ensures models loaded
                            $deletedById = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);
                            if ($deletedById) {
                                $deletedById->delete();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::debug('logout explicit id-delete failed', ['error' => $e->getMessage()]);
                }
                Log::debug('logout tokens after', ['count' => $after]);
            } catch (\Throwable $e) {
                // fallback: delete all tokens for the user
                $user->tokens()->delete();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ], 200);
    }

    public function me(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        // If a bearer token was used, ensure the corresponding Sanctum token still exists.
        $bearer = $request->bearerToken();
        if ($bearer && str_contains($bearer, '|')) {
            [$tokenId] = explode('|', $bearer, 2);
            if (! is_numeric($tokenId) || ! \Laravel\Sanctum\PersonalAccessToken::find((int) $tokenId)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
            }
        }

        // Keep /api/me lean: roles yes, Spatie permission name lists no (unused by dashboards).
        $user->loadMissing(['college:id,name,code', 'program:id,name,code']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
            ],
        ], 200);
    }

    public function joinTeam(Request $request)
    {
        $user = $request->user('api');

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please sign in first.',
            ], 401);
        }

        if ($limited = $this->joinRateLimitResponse($request, $user)) {
            return $limited;
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[A-Za-z0-9]{6}$/'],
        ]);

        if ($this->joinRoleIsRefused($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Deans, QA, VPAA, SuperAdmins, and accreditors cannot join a program with a team code.',
            ], 403);
        }

        if (config('adams.email_verification_enabled') && $user->email_verified_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'Verify your email address before joining a program.',
            ], 403);
        }

        $code = strtoupper($validated['code']);
        $result = DB::transaction(function () use ($code, $user): array {
            $joinCode = TeamJoinCode::query()->where('code', $code)->lockForUpdate()->first();

            if (! $joinCode) {
                return ['status' => 'missing'];
            }

            if ($joinCode->isUsed()) {
                return ['status' => 'used'];
            }

            if ($joinCode->isExpired()) {
                return ['status' => 'expired'];
            }

            if ($user->program_id && (int) $user->program_id !== (int) $joinCode->program_id) {
                return ['status' => 'other_program'];
            }

            if ($user->program_id && (int) $user->program_id === (int) $joinCode->program_id) {
                return ['status' => 'already_member'];
            }

            $joinCode->forceFill([
                'used_by' => $user->id,
                'used_at' => now(),
            ])->save();

            $user->team_id = $joinCode->team_id;
            $user->program_id = $joinCode->program_id;
            $user->save();

            if (! $user->isFaculty()) {
                Role::findOrCreate(RoleSlug::FACULTY, 'web');
                $user->assignRole(RoleSlug::FACULTY);
            }

            ProgramMember::query()->updateOrCreate(
                [
                    'program_id' => $joinCode->program_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => RoleSlug::FACULTY,
                    'joined_at' => now(),
                    'invited_by' => $joinCode->created_by,
                ]
            );

            $joinCode->load('team');

            return [
                'status' => 'joined',
                'joinCode' => $joinCode,
            ];
        });

        if ($result['status'] === 'missing') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid team code. Please check with your Program Chair or Dean.',
            ], 404);
        }

        if ($result['status'] === 'used') {
            return response()->json([
                'success' => false,
                'message' => 'This team code has already been used.',
            ], 422);
        }

        if ($result['status'] === 'expired') {
            return response()->json([
                'success' => false,
                'message' => 'This team code has expired. Ask your Program Chair for a new one.',
            ], 422);
        }

        if ($result['status'] === 'other_program') {
            return response()->json([
                'success' => false,
                'message' => 'This account is already assigned to another program. A Program Chair or SuperAdmin can move you.',
            ], 422);
        }

        if ($result['status'] === 'already_member') {
            return response()->json([
                'success' => false,
                'message' => 'You are already a member of this program.',
            ], 422);
        }

        /** @var TeamJoinCode $joinCode */
        $joinCode = $result['joinCode'];
        $team = $joinCode->team;
        $request->attributes->set('audit_event', 'team_joined');

        return response()->json([
            'success' => true,
            'message' => 'Joined the program as faculty.',
            'data' => [
                'code' => $code,
                'joined' => true,
                'team' => [
                    'id' => $team?->id,
                    'name' => $team?->name,
                    'code' => $joinCode->code,
                    'program_id' => $joinCode->program_id,
                ],
                'user' => new UserResource($user->fresh()),
            ],
        ], 200);
    }

    private function joinRoleIsRefused(User $user): bool
    {
        foreach ([RoleSlug::DEAN, RoleSlug::QA, RoleSlug::VPAA, RoleSlug::SUPERADMIN, RoleSlug::ACCREDITOR] as $role) {
            if ($user->hasCanonicalRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function joinRateLimitResponse(Request $request, User $user): ?\Illuminate\Http\JsonResponse
    {
        $userKey = 'team_join_user:' . $user->id;
        $ipKey = 'team_join_ip:' . ($request->ip() ?? 'unknown');
        $userCount = (int) Cache::get($userKey, 0);
        $ipCount = (int) Cache::get($ipKey, 0);

        if ($userCount >= 8 || $ipCount >= 20) {
            return response()->json([
                'success' => false,
                'message' => 'Too many join attempts. Wait a few minutes and try again.',
            ], 429);
        }

        Cache::put($userKey, $userCount + 1, 600);
        Cache::put($ipKey, $ipCount + 1, 600);

        return null;
    }
}
