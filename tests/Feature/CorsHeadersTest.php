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
}
