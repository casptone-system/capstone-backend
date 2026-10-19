<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Short-lived cache keys that always embed org/user scope so one role can never
 * read another role's payload. No Redis tags — file/database stores only.
 */
class ScopedCache
{
    public static function key(string $domain, array $scope, string $suffix = ''): string
    {
        $parts = ['adams', $domain];
        $scoped = false;
        foreach (['role', 'user_id', 'college_id', 'program_id', 'cycle_id', 'area_id'] as $field) {
            if (! array_key_exists($field, $scope)) {
                continue;
            }
            $value = $scope[$field];
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $field.'='.$value;
            $scoped = true;
        }

        if (! $scoped) {
            throw new InvalidArgumentException('Scoped cache keys require at least one scope field.');
        }

        if ($suffix !== '') {
            $parts[] = $suffix;
        }

        return implode(':', $parts);
    }

    public static function remember(string $domain, array $scope, string $suffix, int $ttlSeconds, callable $callback): mixed
    {
        return Cache::remember(self::key($domain, $scope, $suffix), $ttlSeconds, $callback);
    }

    public static function forget(string $domain, array $scope, string $suffix = ''): void
    {
        Cache::forget(self::key($domain, $scope, $suffix));
    }

    /**
     * Drop common dashboard/list cache entries for a program after a write.
     */
    public static function bustProgram(int $programId, ?int $collegeId = null): void
    {
        foreach (['program-chair', 'dean', 'faculty', 'area-in-charge', 'qa', 'vpaa'] as $role) {
            self::forget('dashboard', [
                'role' => $role,
                'program_id' => $programId,
                'college_id' => $collegeId,
            ], 'summary');
            self::forget('areas', [
                'role' => $role,
                'program_id' => $programId,
            ], 'list');
        }
    }
}
