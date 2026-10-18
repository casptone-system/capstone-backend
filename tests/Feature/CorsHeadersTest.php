<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CorsHeadersTest extends TestCase
{
    public function test_api_exception_responses_allow_the_hosted_frontend_origin(): void
    {
        Route::middleware('api')->get('/api/cors-probe', function () {
            abort(429, 'Too many requests.');
        });

        $this->withHeaders([
            'Origin' => 'https://isu-adams.pages.dev',
            'Accept' => 'application/json',
        ])->get('/api/cors-probe')
            ->assertStatus(429)
            ->assertHeader('Access-Control-Allow-Origin', 'https://isu-adams.pages.dev');
    }

    public function test_preflight_caches_the_hosted_frontend_origin(): void
    {
        $this->withHeaders([
            'Origin' => 'https://isu-adams.pages.dev',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/notifications/unread-count')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://isu-adams.pages.dev')
            ->assertHeader('Access-Control-Max-Age', '7200');
    }

    public function test_unknown_origins_do_not_receive_cors_allow_origin(): void
    {
        Route::middleware('api')->get('/api/cors-origin-probe', function () {
            return response()->json(['success' => true]);
        });

        $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Accept' => 'application/json',
        ])->getJson('/api/cors-origin-probe')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
