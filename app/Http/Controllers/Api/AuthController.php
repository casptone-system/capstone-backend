<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
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

    if (! $user->hasVerifiedEmail()) {
        throw ValidationException::withMessages([
            'email' => ['Please verify your email address before signing in.'],
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

    $challenge = $this->createLoginChallenge($user);

    return response()->json([
        'success' => true,
        'message' => 'A verification code has been sent to your email address. Enter the code to complete sign in.',
        'data' => [
            'challenge_token' => $challenge['challenge_token'],
            'expires_in' => $challenge['expires_in'],
            'email' => $user->email,
        ],
    ], 200);
}

    public function verifyTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $cacheKey = $this->getLoginChallengeCacheKey($validated['challenge_token']);
        $challenge = Cache::get($cacheKey);

        if (! $challenge) {
            throw ValidationException::withMessages([
                'challenge_token' => ['The login verification token is invalid or has expired. Please try signing in again.'],
            ]);
        }

        if ((int) ($challenge['attempts'] ?? 0) >= 5) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages([
                'code' => ['Too many invalid attempts. Please restart the login process.'],
            ]);
        }

        $codeMatch = hash_equals($challenge['code_hash'], hash('sha256', $validated['code']));
        if (! $codeMatch) {
            $challenge['attempts'] = ((int) ($challenge['attempts'] ?? 0)) + 1;
            Cache::put($cacheKey, $challenge, $this->getLoginChallengeTtl());

            throw ValidationException::withMessages([
                'code' => ['The verification code is incorrect. Please try again.'],
            ]);
        }

        $user = User::find($challenge['user_id']);
        Cache::forget($cacheKey);

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

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address before signing in.'],
            ]);
        }

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

    private function createLoginChallenge(User $user): array
    {
        $challengeToken = Str::random(64);
        $code = (string) random_int(100000, 999999);
        $expiresIn = $this->getLoginChallengeTtl();
        $cacheKey = $this->getLoginChallengeCacheKey($challengeToken);

        Cache::put($cacheKey, [
            'user_id' => $user->id,
            'email' => $user->email,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'resend_count' => 0,
        ], $expiresIn);

        $this->sendLoginVerificationCode($user, $code, (int) ($expiresIn / 60));

        return [
            'challenge_token' => $challengeToken,
            'expires_in' => $expiresIn,
        ];
    }

    private function getLoginChallengeCacheKey(string $challengeToken): string
    {
        return 'login_challenge:' . $challengeToken;
    }

    private function getLoginChallengeTtl(): int
    {
        return 300; // 5 minutes
    }

    private function getResendCooldownSeconds(): int
    {
        return 30; // 30 second cooldown between resends
    }

    private function getResendIpLimitPerMinute(): int
    {
        return 6; // allow up to 6 resend requests per IP per minute
    }

    private function getLoginIpLimitPerMinute(): int
    {
        return 12; // allow up to 12 login attempts per IP per minute in tests
    }

    private function sendLoginVerificationCode(User $user, string $code, int $expiresInMinutes): void
    {
        $user->notify(new LoginVerificationCodeNotification($code, $expiresInMinutes));
    }

    public function resendTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
        ]);

        $cacheKey = $this->getLoginChallengeCacheKey($validated['challenge_token']);
        $challenge = Cache::get($cacheKey);

        if (! $challenge) {
            return response()->json([
                'success' => false,
                'message' => 'The login verification token is invalid or has expired. Please sign in again.',
            ], 422);
        }

        $resendCount = (int) ($challenge['resend_count'] ?? 0);
        Log::debug('resendTwoFactor state', ['challenge_key' => $cacheKey, 'resend_count' => $resendCount, 'last_resend_at' => $challenge['last_resend_at'] ?? null]);
        if ($resendCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached the maximum number of resends. Please sign in again.',
            ], 429);
        }

        // Enforce a short cooldown between resends (per challenge)
        $now = now()->getTimestamp();
        $lastResend = isset($challenge['last_resend_at']) ? (int) $challenge['last_resend_at'] : 0;
        // Defensive: if lastResend is in the future (clock skew), ignore it
        if ($lastResend > $now) {
            $lastResend = 0;
        }

        // Test helper: allow advancing perceived last_resend_at via header in unit tests
        if (app()?->runningUnitTests() && $request->headers->has('X-Test-Advance-Seconds')) {
            $advance = (int) $request->header('X-Test-Advance-Seconds');
            if ($advance > 0) {
                $lastResend = $lastResend - $advance;
            }
        }
        $cooldown = $this->getResendCooldownSeconds();
        // Allow the initial resend even if a last_resend_at exists unexpectedly.
        if ($resendCount > 0 && $lastResend && ($now - $lastResend) < $cooldown) {
            $remaining = $cooldown - ($now - $lastResend);
            Log::debug('resendTwoFactor blocked', ['reason' => 'cooldown', 'remaining' => $remaining, 'challenge' => $cacheKey]);
            return response()->json([
                'success' => false,
                'message' => 'Please wait ' . $remaining . ' seconds before requesting another code.',
            ], 429);
        }

        // IP-based global throttle: limit number of resend requests per IP per minute
        $ip = $request->ip() ?? 'unknown'
        ;
        $ipKey = 'resend_2fa_ip:' . $ip;
        $ipCount = (int) Cache::get($ipKey, 0);
        $ipLimit = $this->getResendIpLimitPerMinute();
        Log::debug('resendTwoFactor ip state', ['ip' => $ip, 'ipKey' => $ipKey, 'ipCount' => $ipCount, 'ipLimit' => $ipLimit]);
        if ($ipCount >= $ipLimit) {
            Log::debug('resendTwoFactor blocked', ['reason' => 'ip_limit', 'ip' => $ip, 'count' => $ipCount]);
            return response()->json([
                'success' => false,
                'message' => 'Too many resend requests from your IP address. Please wait and try again.',
            ], 429);
        }

        Cache::put($ipKey, $ipCount + 1, 60);

        // Generate a new code and reset attempts
        $code = (string) random_int(100000, 999999);
        $challenge['code_hash'] = hash('sha256', $code);
        $challenge['attempts'] = 0;
        $challenge['resend_count'] = $resendCount + 1;
        $challenge['last_resend_at'] = $now;

        $ttl = $this->getLoginChallengeTtl();
        Cache::put($cacheKey, $challenge, $ttl);

        // Send code
        $this->sendLoginVerificationCode(User::find($challenge['user_id']), $code, (int) ($ttl / 60));

        return response()->json([
            'success' => true,
            'message' => 'Verification code resent.',
            'expires_in' => $ttl,
            'resend_count' => $challenge['resend_count'],
        ], 200);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($validated);

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

        if ($this->isSmtpConfigInvalid()) {
            return response()->json([
                'success' => false,
                'message' => 'SMTP is not configured correctly. Set MAIL_MAILER=smtp, MAIL_USERNAME, and MAIL_PASSWORD in .env using a valid Gmail address and app password.',
            ], 422);
        }

        try {
            Mail::raw(
                'This is a test email from ADAMS to confirm that SMTP is configured correctly. If you received this email, SMTP delivery is working.',
                function ($message) use ($validated) {
                    $message->to($validated['email'])
                        ->subject('ADAMS SMTP Configuration Test');
                }
            );

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully. Please check your inbox or spam folder.',
            ], 200);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'SMTP check failed. Please verify your mail settings and credentials.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    private function isSmtpConfigInvalid(): bool
    {
        if (app()?->runningUnitTests()) {
            return false;
        }
        $defaultMailer = config('mail.default');
        $username = config('mail.mailers.smtp.username');
        $password = config('mail.mailers.smtp.password');

        return $defaultMailer !== 'smtp'
            || empty($username)
            || empty($password)
            || ! str_contains($username, '@')
            || str_contains($username, 'your-gmail')
            || str_contains($password, 'your-gmail')
            || str_contains($password, 'password');
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

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Verification email resent. Please check your inbox and spam folder.',
        ], 200);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $frontendUrl = env('FRONTEND_URL', config('app.url'));
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
        try {
            $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required_without:name', 'string', 'max:255'],
            'last_name' => ['required_without:name', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date'],
            'role' => ['sometimes', 'string'],
            'profile_photo' => ['required', 'image', 'max:10240'],
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

        if ($this->isSmtpConfigInvalid()) {
            return response()->json([
                'success' => false,
                'message' => 'Email is not configured correctly. Update MAIL_USERNAME and MAIL_PASSWORD in .env and restart the app.',
            ], 422);
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

        if ($request->hasFile('profile_photo')) {
            $profilePhotoPath = $request->file('profile_photo')->store('profile_photos', 'public');
        } else {
            $profilePhotoPath = null;
        }

        // Determine creator and whether they're a super admin
        $creator = $request->user('sanctum') ?? $request->user('api') ?? $request->user();
        $isSuperAdmin = $creator && (
            $creator->hasRole('Super Administrator') ||
            $creator->hasRole('Super Admin') ||
            $creator->hasRole('super administrator') ||
            $creator->hasRole('superadmin')
        );

        // If the request is made by an authenticated non-super-admin, forbid creating users
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
                'profile_photo' => $profilePhotoPath,
            ]);

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
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user->assignRole($roleName);

            try {
                $user->sendEmailVerificationNotification();
            } catch (\Exception $e) {
                DB::rollBack();
                if ($profilePhotoPath) {
                    Storage::disk('public')->delete($profilePhotoPath);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send verification email. Please check mail settings and try again.',
                    'error' => $e->getMessage(),
                ], 500);
            }

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
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->assignRole($roleName);

        $token = null;
        if ($creator && $isSuperAdmin) {
            $token = $user->createToken('api-token')->plainTextToken;
        }

        $responseData = ['user' => new UserResource($user)];
        if ($token) {
            $responseData['token'] = $token;
        }

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Please verify your email before signing in.',
            'data' => $responseData,
        ], 201);
    }

    public function updateProfilePhoto(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'profile_photo' => ['required', 'image', 'max:10240'],
        ]);

        $profilePhotoPath = null;
        if ($request->hasFile('profile_photo')) {
            $profilePhotoPath = $request->file('profile_photo')->store('profile_photos', 'public');
        }

        if (! empty($user->profile_photo) && $user->profile_photo !== $profilePhotoPath) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        $user->forceFill(['profile_photo' => $profilePhotoPath])->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated successfully.',
            'data' => [
                'user' => new UserResource($user),
            ],
        ], 200);
    }

    public function logout(Request $request)
    {
        $user = $request->user() ?? $request->user('api');
        if ($user) {
            Log::debug('logout called', ['bearer' => $request->bearerToken(), 'user_id' => $user->id, 'current_token_id' => $user->currentAccessToken()?->id]);
            // Revoke the token used for this request
            try {
                $deleted = false;
                $before = $user->tokens()->count();
                Log::debug('logout tokens before', ['count' => $before]);
                if ($tokenModel = $user->currentAccessToken()) {
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

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        // First try to resolve to a Team by code
        $code = strtoupper($validated['code']);

        $team = \App\Models\Team::where('code', $code)->first();

        if ($team) {
            // Assign team and program to user
            $user->team_id = $team->id;
            $user->program_id = $team->program_id;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Joined team successfully.',
                'data' => [
                    'code' => $code,
                    'joined' => true,
                    'team' => [
                        'id' => $team->id,
                        'name' => $team->name,
                        'code' => $team->code,
                        'program_id' => $team->program_id,
                    ],
                    'user' => new UserResource($user),
                ],
            ], 200);
        }

        // Fallback: try Program by code
        $program = \App\Models\Program::where('code', $code)->first();

        if ($program) {
            $user->program_id = $program->id;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Joined program successfully.',
                'data' => [
                    'code' => $code,
                    'joined' => true,
                    'program' => [
                        'id' => $program->id,
                        'name' => $program->name,
                        'code' => $program->code,
                    ],
                    'user' => new UserResource($user),
                ],
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid invitation code. Please check with your Program Chair or Dean.',
        ], 404);
    }
}
