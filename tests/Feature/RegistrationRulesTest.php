<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_rejects_underage_invalid_phone_weak_password_and_missing_consent(): void
    {
        config(['adams.email_verification_enabled' => false]);

        $this->postJson('/api/register', [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'email' => 'juan@example.com',
            'phone' => '0917123456789',
            'birthdate' => now()->subYears(20)->toDateString(),
            'sex' => 'other',
            'password' => 'password',
            'password_confirmation' => 'password',
            'privacy_accepted' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone', 'birthdate', 'sex', 'password', 'privacy_accepted']);
    }

    public function test_public_registration_rejects_non_institutional_email(): void
    {
        config(['adams.email_verification_enabled' => false]);

        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@gmail.com',
            'phone' => '09171234567',
            'birthdate' => now()->subYears(21)->toDateString(),
            'sex' => 'female',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'privacy_accepted' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_public_registration_stores_sex_for_an_adult_who_accepts_the_notice(): void
    {
        config(['adams.email_verification_enabled' => false]);

        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@isu.edu.ph',
            'phone' => '09171234567',
            'birthdate' => now()->subYears(21)->toDateString(),
            'sex' => 'female',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'privacy_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.user.sex', 'female');

        $this->assertDatabaseHas('users', [
            'email' => 'maria@isu.edu.ph',
            'sex' => 'female',
            'phone_number' => '09171234567',
        ]);

        $this->assertTrue(User::where('email', 'maria@isu.edu.ph')->exists());
    }
}
