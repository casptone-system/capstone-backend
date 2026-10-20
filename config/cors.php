<?php

$parseOrigins = static function (string $value): array {
    if ($value === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $item): string => rtrim(trim($item), '/'),
        explode(',', $value)
    )));
};

$isLocal = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

$localOrigins = [
    'http://localhost:8080',
    'http://localhost:8081',
    'http://127.0.0.1:8080',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
];

$knownHostedOrigins = [
    'https://isu-adams.pages.dev',
    'https://adams.pages.dev',
    'https://adams-5od.pages.dev',
    'https://adams-frontend.pages.dev',
];

$configuredOrigins = $parseOrigins((string) env('CORS_ALLOWED_ORIGINS', ''));

$frontendOrigin = rtrim((string) env('FRONTEND_URL', ''), '/');
if ($frontendOrigin !== '' && str_starts_with($frontendOrigin, 'http')) {
    $configuredOrigins[] = $frontendOrigin;
}

// Production/staging: explicit env origins (+ FRONTEND_URL) plus the known
// deployed Pages hosts. The Pages hosts must stay allow-listed in production
// too — previously they were local-only, so a Render deploy missing
// CORS_ALLOWED_ORIGINS/FRONTEND_URL answered the SPA (e.g. GET /sync/cursor)
// with no Access-Control-Allow-Origin header and the browser blocked it.
// Local/testing additionally allow the localhost ports used by Vue CLI.
$allowedOrigins = $isLocal
    ? array_values(array_unique(array_merge($localOrigins, $knownHostedOrigins, $configuredOrigins)))
    : array_values(array_unique(array_merge($knownHostedOrigins, $configuredOrigins)));

$allowedOriginPatterns = [];
if ($isLocal) {
    $allowedOriginPatterns = [
        '/^https?:\/\/(localhost|127\.0\.0\.1):\d+$/',
    ];
}

// Optional extra patterns from env (comma-separated regexes), for preview
// deployments that share a known host suffix.
foreach ($parseOrigins((string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')) as $pattern) {
    $allowedOriginPatterns[] = $pattern;
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Socket-Id',
    ],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 7200,

    'supports_credentials' => true,
];
