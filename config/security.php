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
];
