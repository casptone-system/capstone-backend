<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
    }

    public function test_registration_requires_profile_photo(): void
    {
        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $admin->assignRole('Super Administrator');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/register', [
            'first_name' => 'No',
            'last_name' => 'Photo',
            'email' => 'nophoto@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.profile_photo.0', 'A profile photo is required.');
    }

    public function test_registration_accepts_valid_profile_photo_and_exposes_it_in_me_response(): void
    {
        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin2@example.com',
            'password' => 'secret123',
        ]);
        $admin->assignRole('Super Administrator');

        $photo = UploadedFile::fake()->create('avatar.png', 120, 'image/png');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/register', [
            'first_name' => 'Valid',
            'last_name' => 'Photo',
            'email' => 'validphoto@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'profile_photo' => $photo,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $user = User::where('email', 'validphoto@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotEmpty($user->profile_photo);
        Storage::disk('public')->assertExists($user->profile_photo);

        $meResponse = $this->actingAs($user, 'sanctum')->getJson('/api/me');
        $meResponse->assertStatus(200)
            ->assertJsonPath('data.user.email', 'validphoto@example.com');

        $profilePhoto = $meResponse->json('data.user.profilePhoto');
        $this->assertIsString($profilePhoto);
        $this->assertStringContainsString('/storage/', $profilePhoto);
    }

    public function test_invalid_profile_photo_is_rejected(): void
    {
        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin3@example.com',
            'password' => 'secret123',
        ]);
        $admin->assignRole('Super Administrator');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/register', [
            'first_name' => 'Bad',
            'last_name' => 'Photo',
            'email' => 'badphoto@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'profile_photo' => UploadedFile::fake()->create('document.pdf', 120, 'application/pdf'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.profile_photo.0', 'The profile photo must be an image file.');
    }

    public function test_current_user_can_replace_existing_profile_photo(): void
    {
        $user = User::factory()->create([
            'name' => 'Replace Photo',
            'email' => 'replace@example.com',
            'password' => 'secret123',
        ]);

        $originalPath = 'profile_photos/original.jpg';
        Storage::disk('public')->put($originalPath, 'original');
        $user->forceFill(['profile_photo' => $originalPath])->save();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/me/profile-photo', [
            'profile_photo' => UploadedFile::fake()->create('replacement.png', 120, 'image/png'),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertNotSame($originalPath, $user->profile_photo);
        $this->assertNotEmpty($user->profile_photo);
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($user->profile_photo);
    }
}
