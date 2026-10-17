<?php

$parseCsv = static function (string $value): array {
    if ($value === '') {
        return [];
    }

    return array_values(array_filter(array_map(static function (string $item): string {
        return trim($item);
    }, preg_split('/,/', $value) ?: [])));
};

return [
    'force_https' => env('SECURITY_FORCE_HTTPS', false),
    'blocked_ips' => $parseCsv((string) env('SECURITY_BLOCKED_IPS', '')),
    'required_roles' => $parseCsv((string) env('SECURITY_REQUIRED_ROLES', '')),
    'required_permissions' => $parseCsv((string) env('SECURITY_REQUIRED_PERMISSIONS', '')),

    /*
    |--------------------------------------------------------------------------
    | HTTP security response headers
    |--------------------------------------------------------------------------
    |
    | Applied globally by AddSecurityHeaders. CSP for the JSON API is
    | intentionally strict; the Vue SPA sets its own CSP via hosting headers.
    |
    */
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'no-referrer'),
        'permissions_policy' => env(
            'SECURITY_PERMISSIONS_POLICY',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
        ),
        'content_security_policy' => env(
            'SECURITY_CSP',
            "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; img-src 'none'; script-src 'none'; style-src 'none'; connect-src 'self'"
        ),
        'hsts_enabled' => env('SECURITY_HSTS_ENABLED', true),
        'hsts' => env(
            'SECURITY_HSTS',
            'max-age=31536000; includeSubDomains; preload'
        ),
        'no_store_api' => env('SECURITY_API_NO_STORE', true),
        'cache_control' => env(
            'SECURITY_CACHE_CONTROL',
            'no-store, no-cache, must-revalidate, private'
        ),
    ],
];
