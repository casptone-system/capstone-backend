<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        if (! (bool) config('security.headers.enabled', true)) {
            return $response;
        }

        $headers = [
            'X-Frame-Options' => (string) config('security.headers.x_frame_options', 'DENY'),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => (string) config('security.headers.referrer_policy', 'no-referrer'),
            'Permissions-Policy' => (string) config(
                'security.headers.permissions_policy',
                'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
            ),
            // COOP is safe on JSON APIs. Do not set Cross-Origin-Resource-Policy
            // to same-origin here — the Vue SPA is hosted on a different origin.
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        $csp = trim((string) config('security.headers.content_security_policy', ''));
        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        if ($this->shouldSendHsts($request)) {
            $headers['Strict-Transport-Security'] = (string) config(
                'security.headers.hsts',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        foreach ($headers as $name => $value) {
            if ($value === '' || $response->headers->has($name)) {
                continue;
            }

            $response->headers->set($name, $value);
        }

        // Always harden API cache policy (Laravel may already set a weaker value).
        if ($this->shouldSendNoStoreCache($request)) {
            $response->headers->set(
                'Cache-Control',
                (string) config(
                    'security.headers.cache_control',
                    'no-store, no-cache, must-revalidate, private'
                )
            );
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    protected function shouldSendHsts(Request $request): bool
    {
        if (! (bool) config('security.headers.hsts_enabled', true)) {
            return false;
        }

        if ((bool) config('security.force_https', false)) {
            return true;
        }

        if (app()->environment('production')) {
            return true;
        }

        if ($request->isSecure()) {
            return true;
        }

        $forwardedProto = $request->header('X-Forwarded-Proto');
        if ($forwardedProto) {
            foreach (explode(',', $forwardedProto) as $proto) {
                if (trim($proto) === 'https') {
                    return true;
                }
            }
        }

        return false;
    }

    protected function shouldSendNoStoreCache(Request $request): bool
    {
        if (! (bool) config('security.headers.no_store_api', true)) {
            return false;
        }

        return $request->is('api/*')
            || $request->is('sanctum/csrf-cookie')
            || $request->expectsJson();
    }
}
