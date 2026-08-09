<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyApiEmailNotification;
use App\Notifications\LoginVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
    }

    public function test_registration_sends_verification_email()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Reg',
            'last_name' => 'User',
            'email' => 'reg@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $resp->assertStatus(201)->assertJsonPath('success', true);

        $user = User::where('email', 'reg@example.test')->first();
        $this->assertNotNull($user);

        Notification::assertSentTo($user, VerifyApiEmailNotification::class);
    }

    public function test_unverified_account_cannot_login()
    {
        $user = User::factory()->unverified()->create(['password' => bcrypt('secret123')]);

        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $resp->assertStatus(422);
        $this->assertStringContainsString('verify', $resp->json('errors.email')[0] ?? $resp->json('message'));
    }

    public function test_successful_login_with_2fa_and_token_only_after_verification()
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);

        // Step 1: login returns challenge
        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $resp->assertStatus(200)->assertJsonPath('success', true);
        $this->assertArrayNotHasKey('token', $resp->json('data'));

        $challenge = $resp->json('data');
        $this->assertArrayHasKey('challenge_token', $challenge);

        $cacheKey = 'login_challenge:' . $challenge['challenge_token'];
        $this->assertNotNull(Cache::get($cacheKey));

        // Replace code_hash with known code for deterministic verify
        $challengeData = Cache::get($cacheKey);
        $knownCode = '123456';
        $challengeData['code_hash'] = hash('sha256', $knownCode);
        Cache::put($cacheKey, $challengeData, 300);

        // Now verify
        $verifyResp = $this->postJson('/api/auth/verify-2fa', ['challenge_token' => $challenge['challenge_token'], 'code' => $knownCode]);
        $verifyResp->assertStatus(200)->assertJsonPath('success', true);
        $this->assertArrayHasKey('token', $verifyResp->json('data'));
    }

    public function test_invalid_2fa_code_is_rejected()
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);

        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $challenge = $resp->json('data');

        $challengeKey = 'login_challenge:' . $challenge['challenge_token'];
        $challengeData = Cache::get($challengeKey);
        $challengeData['code_hash'] = hash('sha256', '999999');
        Cache::put($challengeKey, $challengeData, 300);

        $bad = $this->postJson('/api/auth/verify-2fa', ['challenge_token' => $challenge['challenge_token'], 'code' => '000000']);
        $bad->assertStatus(422);
    }

    public function test_expired_2fa_code_is_rejected()
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);

        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $challenge = $resp->json('data');

        $cacheKey = 'login_challenge:' . $challenge['challenge_token'];
        Cache::forget($cacheKey);

        $expired = $this->postJson('/api/auth/verify-2fa', ['challenge_token' => $challenge['challenge_token'], 'code' => '123456']);
        $expired->assertStatus(422);
    }

    public function test_maximum_2fa_attempts_blocks()
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);

        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $challenge = $resp->json('data');
        $cacheKey = 'login_challenge:' . $challenge['challenge_token'];

        $challengeData = Cache::get($cacheKey);
        $challengeData['code_hash'] = hash('sha256', '111111');
        $challengeData['attempts'] = 4; // one away from limit
        Cache::put($cacheKey, $challengeData, 300);

        // Wrong code -> increments to 5 and should then be blocked on next attempt
        $this->postJson('/api/auth/verify-2fa', ['challenge_token' => $challenge['challenge_token'], 'code' => '000000'])->assertStatus(422);

        // Now any attempt should be treated as invalid token (since controller forgets cache when >=5), so second attempt
        $this->postJson('/api/auth/verify-2fa', ['challenge_token' => $challenge['challenge_token'], 'code' => '111111'])->assertStatus(422);
    }

    public function test_resend_code_throttling_and_limits()
    {
        Notification::fake();
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);

        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $challenge = $resp->json('data');
        $token = $challenge['challenge_token'];

        // First resend ok
        $this->postJson('/api/auth/resend-2fa', ['challenge_token' => $token])->assertStatus(200);

        // Immediate resend should be blocked by cooldown
        $this->postJson('/api/auth/resend-2fa', ['challenge_token' => $token])->assertStatus(429);

        // Simulate cooldown passing
        $data = Cache::get('login_challenge:' . $token);
        $data['last_resend_at'] = time() - 31;
        Cache::put('login_challenge:' . $token, $data, 300);

        // Resend up to limit (3)
        $this->postJson('/api/auth/resend-2fa', ['challenge_token' => $token])->assertStatus(200);
        $this->postJson('/api/auth/resend-2fa', ['challenge_token' => $token])->assertStatus(200);
        $this->postJson('/api/auth/resend-2fa', ['challenge_token' => $token])->assertStatus(429);
    }

    public function test_login_ip_throttling()
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);
        $ip = '198.51.100.7';

        // Make more than allowed attempts
        $limit = 12;
        for ($i = 0; $i < $limit + 2; $i++) {
            $resp = $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);
            if ($i < $limit) {
                $resp->assertStatus(422);
            } else {
                $resp->assertStatus(422);
                $this->assertStringContainsString('Too many login attempts', $resp->json('errors.email')[0] ?? $resp->json('message'));
            }
        }
    }

    public function test_token_not_issued_before_2fa()
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);
        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $resp->assertStatus(200);
        $this->assertArrayNotHasKey('token', $resp->json('data'));
    }

    public function test_logout_and_protected_route_access()
    {
        $user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);

        // Create challenge and set known code
        $resp = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123']);
        $challenge = $resp->json('data');
        $cacheKey = 'login_challenge:' . $challenge['challenge_token'];
        $data = Cache::get($cacheKey);
        $data['code_hash'] = hash('sha256', '654321');
        Cache::put($cacheKey, $data, 300);

        $verify = $this->postJson('/api/auth/verify-2fa', ['challenge_token' => $challenge['challenge_token'], 'code' => '654321']);
        $token = $verify->json('data.token');

        // Access protected route
        $me = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->getJson('/api/me');
        $me->assertStatus(200);

        // Logout
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/logout')->assertStatus(200);

        // Now protected route should be unauthorized
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->getJson('/api/me')->assertStatus(401);
    }
}
