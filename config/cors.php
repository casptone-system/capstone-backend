<?php

$defaultOrigins = [
    'http://localhost:8080',
    'http://localhost:8081',
    'http://127.0.0.1:8080',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'https://adams.pages.dev',
    'https://adams-5od.pages.dev',
    'https://adams-frontend.pages.dev',
];

$configuredOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

$frontendOrigin = rtrim((string) env('FRONTEND_URL', ''), '/');
if ($frontendOrigin !== '' && str_starts_with($frontendOrigin, 'http')) {
    $configuredOrigins[] = $frontendOrigin;
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_merge($defaultOrigins, $configuredOrigins))),

    'allowed_origins_patterns' => [
        '/^https?:\/\/(localhost|127\.0\.0\.1):\d+$/',
        '/^https:\/\/([a-z0-9-]+\.)?adams(-[a-z0-9]+)?\.pages\.dev$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
