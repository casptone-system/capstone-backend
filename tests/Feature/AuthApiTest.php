<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user(): void
    {
        $user = User::factory()->create([
            'name' => 'API Tester',
            'email' => 'api@example.com',
            'password' => 'secret123',
        ]);

        // Login triggers a 2FA challenge; follow verify flow to obtain token
        $response = $this->postJson('/api/login', [
            'email' => 'api@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $challenge = $response->json('data');
        $this->assertArrayHasKey('challenge_token', $challenge);

        // Make the verification deterministic by setting known code in cache
        $cacheKey = 'login_challenge:' . $challenge['challenge_token'];
        \Illuminate\Support\Facades\Cache::put($cacheKey, array_merge(\Illuminate\Support\Facades\Cache::get($cacheKey, []), ['code_hash' => hash('sha256', '000000')]), 300);

        $verify = $this->postJson('/api/auth/verify-2fa', ['challenge_token' => $challenge['challenge_token'], 'code' => '000000']);
        $verify->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('data.user.email', 'api@example.com');
    }

    public function test_super_admin_can_create_user(): void
    {
        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);

        $admin->assignRole('Super Administrator');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'faculty',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'newuser@example.com')
            ->assertJsonPath('data.user.role', 'faculty')
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'first_name' => 'New',
            'last_name' => 'User',
        ]);
    }

    public function test_register_requires_super_admin(): void
    {
        $user = User::factory()->create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/register', [
            'first_name' => 'Blocked',
            'last_name' => 'User',
            'email' => 'blocked@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'faculty',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }
}

