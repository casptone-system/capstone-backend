<?php

$parseCsv = static function (string $value): array {
    if ($value === '') {
        return [];
    }

    return array_values(array_filter(array_map(static function (string $item): string {
        return trim($item);
    }, preg_split('/,/', $value) ?: [])));
};

$enabledInProduction = static function (string $key): bool {
    $raw = env($key);
    if ($raw === null || $raw === '') {
        // When APP_ENV is omitted on the host, Laravel still treats the app as
        // production. Mirror that here so auth/mail features default ON in prod.
        return (string) env('APP_ENV', 'production') === 'production';
    }

    return filter_var($raw, FILTER_VALIDATE_BOOL);
};

return [
    /*
    | Flags default on in production and off in local/testing when the env
    | value is omitted. Set them explicitly in .env to override.
    |
    | Local 2FA test (seeded @example.com accounts cannot receive mail):
    |   AUTH_2FA_ENABLED=true
    |   AUTH_EMAIL_VERIFICATION_ENABLED=true
    |   TEAM_INVITES_ENABLED=true
    |   MAIL_MAILER=log
    | Then sign in as a role that requires a code (dean, program chair, QA,
    | VPAA, superadmin, accreditor). The 6-digit code is written to
    | storage/logs/laravel.log. Faculty and area-in-charge skip the code.
    */
    'two_factor_enabled' => $enabledInProduction('AUTH_2FA_ENABLED'),
    'email_verification_enabled' => $enabledInProduction('AUTH_EMAIL_VERIFICATION_ENABLED'),
    'team_invites_enabled' => $enabledInProduction('TEAM_INVITES_ENABLED'),

    'two_factor_roles' => $parseCsv((string) env(
        'AUTH_2FA_ROLES',
        'superadmin,qa,vpaa,dean,program-chair,accreditor'
    )),

    // Emails that skip the login OTP (e.g. break-glass @example.com accounts).
    'two_factor_exempt_emails' => array_map(
        static fn (string $email): string => strtolower($email),
        $parseCsv((string) env('AUTH_2FA_EXEMPT_EMAILS', 'admin@example.com'))
    ),

    'institutional_email_domain' => strtolower((string) env('INSTITUTIONAL_EMAIL_DOMAIN', 'isu.edu.ph')),

    'two_factor_ttl_seconds' => 300,
    'two_factor_max_attempts' => 5,
    'two_factor_lockout_seconds' => 900,
    'two_factor_resend_cooldown_seconds' => 30,
    'two_factor_max_resends' => 3,
    'email_resend_cooldown_seconds' => 60,
];
