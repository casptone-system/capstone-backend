<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
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
        Storage::fake('public');

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
            'profile_photo' => UploadedFile::fake()->create('avatar.png', 120, 'image/png'),
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

    public function test_super_admin_can_create_dean_with_program_id(): void
    {
        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $admin->assignRole('Super Administrator');

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', [
            'first_name' => 'Dean',
            'last_name' => 'User',
            'email' => 'dean@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'dean',
            'program_id' => $program->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'dean')
            ->assertJsonPath('data.programId', $program->id);

        $this->assertDatabaseHas('users', [
            'email' => 'dean@example.com',
            'program_id' => $program->id,
        ]);

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => Role::where('name', 'dean')->first()->id,
            'model_type' => User::class,
            'model_id' => User::where('email', 'dean@example.com')->first()->id,
        ]);
    }

    public function test_super_admin_cannot_create_dean_without_college_program_or_team(): void
    {
        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $admin->assignRole('Super Administrator');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', [
            'first_name' => 'Dean',
            'last_name' => 'User',
            'email' => 'dean2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'dean',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.college_id.0', 'A dean must belong to a college.');
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

