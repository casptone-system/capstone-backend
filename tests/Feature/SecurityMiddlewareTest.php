<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_https_requests_are_rejected_when_https_enforcement_is_enabled(): void
    {
        config(['security.force_https' => true]);
        config(['app.env' => 'production']);

        $response = $this->withHeader('X-Forwarded-Proto', 'http')->getJson('/api/health');

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'HTTPS is required for this API.']);
    }

    public function test_blocked_ips_are_rejected(): void
    {
        config(['security.blocked_ips' => ['127.0.0.1']]);
        config(['security.force_https' => false]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Access denied by security policy.']);
    }
}
