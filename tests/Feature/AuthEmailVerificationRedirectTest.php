<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthEmailVerificationRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_email_redirects_to_frontend_verification_page(): void
    {
        putenv('FRONTEND_URL=http://localhost:8080');

        $user = User::factory()->create([
            'email' => 'verify-redirect@example.com',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $response = $this->get($url);

        $response->assertStatus(302);
        $response->assertRedirectContains('http://localhost:8080/email-verified?status=success');
    }
}
