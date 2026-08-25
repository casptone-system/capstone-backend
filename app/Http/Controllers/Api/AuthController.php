<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
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

    // DISABLED: Email verification check — temporarily off for dev, see [2026-08-18]
    // if (! $user->hasVerifiedEmail()) {
    //     throw ValidationException::withMessages([
    //         'email' => ['Please verify your email address before signing in.'],
    //     ]);
    // }

    // DISABLED: Duplicate IP-based login throttle check (first check already done above) — keeping removal for dev, see [2026-08-18]
    // $ip = $request->ip() ?? 'unknown';
    // $ipKey = 'login_ip_attempts:' . $ip;
    // $ipCount = (int) Cache::get($ipKey, 0);
    // $ipLimit = $this->getLoginIpLimitPerMinute();
    // if ($ipCount >= $ipLimit) {
    //     throw ValidationException::withMessages([
    //         'email' => ['Too many login attempts from your IP address. Please wait and try again.'],
    //     ]);
    // }
    // Cache::put($ipKey, $ipCount + 1, 60);

    // DISABLED: 2FA code generation & sending — temporarily off for dev, see [2026-08-18]
    // $challenge = $this->createLoginChallenge($user);

    // REPLACEMENT: Return auth token immediately after password validation (2FA skipped)
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

    public function verifyTwoFactor(Request $request)
    {
        // DISABLED: 2FA verification — backend skips code generation, see [2026-08-18]
        // Original method body commented out for restoration:
        // $validated = $request->validate([
        //     'challenge_token' => ['required', 'string'],
        //     'code' => ['required', 'string', 'size:6'],
        // ]);
        // ... validation logic ...
        // $token = $user->createToken('api-token')->plainTextToken;

        // Return graceful no-op response
        return response()->json([
            'success' => false,
            'message' => '2FA verification is skipped for development. Please use the login endpoint which now returns a token directly.',
        ], 400);
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
        // DISABLED: 2FA resend — skipped in login, see [2026-08-18]
        // Original logic: validate challenge token, check resend count, enforce cooldown, send new code
        // 2FA code generation is now skipped entirely in login()

        return response()->json([
            'success' => false,
            'message' => '2FA resend is skipped for development. The login endpoint now returns a token directly.',
        ], 400);
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
        // DISABLED: Email verification resend — no longer needed for dev, see [2026-08-18]
        // Original logic: check if user exists, check if already verified, send verification email
        // All users are now auto-verified on registration

        return response()->json([
            'success' => true,
            'message' => 'Email verification skipped for development. All users are auto-verified on registration.',
        ], 200);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        // DISABLED: Email verification endpoint — no longer needed for dev, see [2026-08-18]
        // Original logic: validate signature, check user exists, verify hash, mark email verified
        // All users are now auto-verified on registration

        $frontendUrl = env('FRONTEND_URL', config('app.frontend_url', config('app.url')));
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
        $isSuperAdmin = $creator && $creator->isSuperAdmin();

        $profilePhotoRules = ['nullable', 'image', 'max:10240'];
        if ($isSuperAdmin) {
            $profilePhotoRules = ['required', 'image', 'max:10240'];
        }

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
            'profile_photo' => $profilePhotoRules,
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
        $isSuperAdmin = $creator && $creator->isSuperAdmin();

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
                'profile_photo' => $profilePhotoPath,
            ]);

            // Role assignment rules:
            // - Only authenticated Super Administrators may create accounts with an elevated role.
            // - Otherwise default to the standard faculty role.
            $creator = $request->user('sanctum') ?? $request->user('api') ?? $request->user();
            $role = RoleSlug::FACULTY;
            $isSuperAdmin = $creator && $creator->isSuperAdmin();

            if (! empty($validated['role']) && $isSuperAdmin) {
                $role = RoleSlug::canonicalize($validated['role']) ?? RoleSlug::FACULTY;
            }

            $roleModel = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($roleModel);
            $this->assignDefaultPermissionsToRole($roleModel, $role);

            if (RoleSlug::isInstitutionWide($role)) {
                $user->forceFill([
                    'college_id' => null,
                    'program_id' => null,
                    'team_id' => null,
                ])->save();
            }

            // DISABLED: Email verification notification — temporarily off for dev, see [2026-08-18]
            // Instead of sending verification email, auto-mark email as verified:
            $user->markEmailAsVerified();
            // try {
            //     $user->sendEmailVerificationNotification();
            // } catch (\Exception $e) {
            //     ...
            // }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            if (! empty($profilePhotoPath)) {
                Storage::disk('public')->delete($profilePhotoPath);
            }
            throw $e;
        }

        $creator = $request->user('sanctum') ?? $request->user('api') ?? $request->user();
        $isSuperAdmin = $creator && $creator->isSuperAdmin();

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
            // DISABLED: Email verification — temporarily off for dev, see [2026-08-18]
            // Changed from: 'message' => 'Registration successful. Please verify your email before signing in.'
            'message' => 'Registration successful. Email verification skipped for development.',
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

        $team = \App\Models\Team::with('program')->where('code', $code)->first();

        if ($team) {
            $this->assertCanJoinProgram($user, (int) $team->program_id);

            // Membership source of truth is users.program_id.
            $user->team_id = $team->id;
            $user->program_id = $team->program_id;
            if (! $user->isDean()) {
                $user->college_id = $team->program?->college_id;
            }
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
            $this->assertCanJoinProgram($user, (int) $program->id);

            $user->program_id = $program->id;
            if (! $user->isDean()) {
                $user->college_id = $program->college_id;
            }
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

    private function assertCanJoinProgram($user, int $programId): void
    {
        if ($user->isQA() || $user->isVPAA() || $user->isSuperAdmin() || $user->isAccreditor()) {
            abort(403, 'Institution-wide roles are not assigned to a program via team code.');
        }

        if ($user->isDean()) {
            abort(403, 'Deans are assigned to a college, not via team code.');
        }

        if ($user->isProgramChair()) {
            $chairedId = $user->chairedProgramId();
            if ($chairedId && $chairedId !== $programId) {
                abort(422, 'You already chair a different program.');
            }
        }
    }
}
