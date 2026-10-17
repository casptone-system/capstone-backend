<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/security-headers-probe', function () {
            return response()->json(['success' => true]);
        });
    }

    public function test_api_responses_include_zap_hardening_headers(): void
    {
        $response = $this->getJson('/api/security-headers-probe');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Cache-Control');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_hsts_is_sent_when_https_enforcement_is_enabled(): void
    {
        // force_https flips HSTS on without needing a TLS-terminated test request.
        // The probe route does not use EnforceSecurityPolicy, so this stays a 200.
        config(['security.force_https' => true]);

        $this->getJson('/api/security-headers-probe')
            ->assertOk()
            ->assertHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
    }
}
