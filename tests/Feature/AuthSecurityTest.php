<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\Program;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use App\Notifications\VerifyApiEmailNotification;
use App\Support\LoginChallenge;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'adams.two_factor_enabled' => true,
            'adams.email_verification_enabled' => true,
            'adams.team_invites_enabled' => true,
        ]);
    }

    public function test_faculty_login_returns_a_token_without_a_code(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::FACULTY);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        Notification::assertNothingSent();
    }

    public function test_exempt_vpaa_qa_and_accreditor_login_without_a_code(): void
    {
        Notification::fake();

        $accounts = [
            RoleSlug::VPAA => 'vpaa@isu.edu.ph',
            RoleSlug::QA => 'qa@isu.edu.ph',
            RoleSlug::ACCREDITOR => 'accreditor@isu.edu.ph',
        ];

        foreach ($accounts as $role => $email) {
            $user = $this->userWithRole($role, ['email' => $email]);

            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

            $response->assertOk();
            $this->assertNotEmpty($response->json('data.token'), "{$email} should receive a token directly.");
            $this->assertEmpty($response->json('data.challenge_token'), "{$email} should not receive an OTP challenge.");
        }

        Notification::assertNothingSent();
    }

    public function test_dean_login_returns_a_challenge_instead_of_a_token(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::DEAN);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.token', null);
        $this->assertNotEmpty($response->json('data.challenge_token'));
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_wrong_code_is_rejected_and_the_right_code_issues_a_token(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::QA);
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $challenge = $login->json('data.challenge_token');

        $this->postJson('/api/auth/verify-2fa', [
            'challenge_token' => $challenge,
            'code' => '000000',
        ])->assertStatus(422);

        $this->postJson('/api/auth/verify-2fa', [
            'challenge_token' => $challenge,
            'code' => $this->sentCode($user),
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'device_token']]);
    }

    public function test_trusted_device_skips_otp_on_the_next_login(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::DEAN);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'trust_device' => true,
        ])->assertOk();

        $verified = $this->postJson('/api/auth/verify-2fa', [
            'challenge_token' => $login->json('data.challenge_token'),
            'code' => $this->sentCode($user),
            'trust_device' => true,
        ])->assertOk();

        $deviceToken = $verified->json('data.device_token');
        $this->assertNotEmpty($deviceToken);
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);

        Notification::fake();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_token' => $deviceToken,
            'trust_device' => true,
        ])->assertOk()
            ->assertJsonPath('data.challenge_token', null)
            ->assertJsonStructure(['data' => ['token', 'device_token']]);

        Notification::assertNothingSent();
    }

    public function test_new_device_still_requires_otp_after_another_device_is_trusted(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::QA);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/auth/verify-2fa', [
            'challenge_token' => $login->json('data.challenge_token'),
            'code' => $this->sentCode($user),
            'trust_device' => true,
        ])->assertOk();

        Notification::fake();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.token', null)
            ->assertJsonStructure(['data' => ['challenge_token']]);

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_password_reset_revokes_trusted_devices(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::DEAN);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $deviceToken = $this->postJson('/api/auth/verify-2fa', [
            'challenge_token' => $login->json('data.challenge_token'),
            'code' => $this->sentCode($user),
            'trust_device' => true,
        ])->assertOk()->json('data.device_token');

        $resetToken = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'Newpassword1',
            'password_confirmation' => 'Newpassword1',
        ])->assertOk();

        Notification::fake();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Newpassword1',
            'device_token' => $deviceToken,
        ])->assertOk()
            ->assertJsonPath('data.token', null)
            ->assertJsonStructure(['data' => ['challenge_token']]);

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_expired_challenge_is_rejected(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::VPAA);
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->travel(6)->minutes();

        $this->postJson('/api/auth/verify-2fa', [
            'challenge_token' => $login->json('data.challenge_token'),
            'code' => $this->sentCode($user),
        ])->assertStatus(422);
    }

    public function test_five_wrong_codes_lock_the_account_out_of_new_challenges(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::ACCREDITOR);
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $challenge = $login->json('data.challenge_token');

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/auth/verify-2fa', [
                'challenge_token' => $challenge,
                'code' => '11111' . $attempt,
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/verify-2fa', [
            'challenge_token' => $challenge,
            'code' => '999999',
        ])->assertStatus(422);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertNotNull(LoginChallenge::lockoutMessage((int) $user->id));
    }

    public function test_resend_is_rate_limited_until_the_cooldown_passes(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $challenge = $login->json('data.challenge_token');

        $this->postJson('/api/auth/resend-2fa', [
            'challenge_token' => $challenge,
        ])->assertOk();

        $this->postJson('/api/auth/resend-2fa', [
            'challenge_token' => $challenge,
        ])->assertStatus(429);

        $this->travel(31)->seconds();

        $this->postJson('/api/auth/resend-2fa', [
            'challenge_token' => $challenge,
        ])->assertOk();
    }

    public function test_smtp_failure_returns_a_clear_error_instead_of_a_token(): void
    {
        $user = $this->userWithRole(RoleSlug::DEAN);
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Notifications\Dispatcher::class);
        $dispatcher->shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(503)
            ->assertJsonPath('message', 'We could not send the verification code. Try again in a few minutes.');
    }

    public function test_password_reset_does_not_issue_a_token_and_the_next_login_still_requires_a_code(): void
    {
        Notification::fake();
        $user = $this->userWithRole(RoleSlug::DEAN);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Newpassword1',
            'password_confirmation' => 'Newpassword1',
        ])->assertOk()->assertJsonMissingPath('data.token');

        $this->assertTrue(Hash::check('Newpassword1', $user->fresh()->password));

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Newpassword1',
        ])->assertOk()->assertJsonPath('data.token', null);
    }

    public function test_unverified_user_can_reach_me_and_logout_but_not_protected_routes(): void
    {
        $user = User::factory()->unverified()->create();
        Role::findOrCreate(RoleSlug::FACULTY, 'web');
        $user->assignRole(RoleSlug::FACULTY);

        $this->actingAs($user);

        $this->getJson('/api/me')->assertOk();
        $this->getJson('/api/programs')->assertStatus(403)->assertJsonPath('code', 'email_unverified');
        $this->postJson('/api/logout')->assertOk();
    }

    public function test_superadmin_can_mark_an_email_verified_and_clear_a_login_challenge(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(RoleSlug::SUPERADMIN);
        $target = User::factory()->unverified()->create();
        LoginChallenge::issue($target);

        $this->actingAs($admin);

        $this->postJson("/api/admin/users/{$target->id}/mark-email-verified")
            ->assertOk();
        $this->assertNotNull($target->fresh()->email_verified_at);

        $this->postJson("/api/admin/users/{$target->id}/reset-2fa")->assertOk();
        $this->assertNull(LoginChallenge::lockoutMessage((int) $target->id));

        $this->assertTrue(
            AuditLog::query()->where('event', 'EMAIL_MARKED_VERIFIED')->where('user_id', $admin->id)->exists()
        );
        $this->assertTrue(
            AuditLog::query()->where('event', 'TWO_FACTOR_RESET')->where('user_id', $admin->id)->exists()
        );
    }

    public function test_superadmin_email_correction_clears_verification_and_sends_a_new_link(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(RoleSlug::SUPERADMIN);
        $target = $this->userWithRole(RoleSlug::FACULTY);

        $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}", [
            'email' => 'corrected-' . $target->id . '@example.test',
        ])->assertOk();

        $fresh = $target->fresh();
        $this->assertSame('corrected-' . $target->id . '@example.test', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
        Notification::assertSentTo($fresh, VerifyApiEmailNotification::class);
        $this->assertTrue(AuditLog::query()->where('event', 'EMAIL_CORRECTED')->exists());
    }

    public function test_break_glass_command_verifies_the_email_and_clears_challenges(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        LoginChallenge::issue($user);

        $this->artisan('auth:reset-user', ['email' => $user->email])
            ->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue(AuditLog::query()->where('event', 'AUTH_BREAK_GLASS_RESET')->exists());
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function sentCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($notification) use (&$code) {
            $property = (new ReflectionClass($notification))->getProperty('code');
            $property->setAccessible(true);
            $code = $property->getValue($notification);

            return true;
        });

        return (string) $code;
    }
}
