<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class LoginChallenge
{
    public static function ttl(): int
    {
        return (int) config('adams.two_factor_ttl_seconds', 300);
    }

    public static function issue(User $user): array
    {
        self::forgetForUser((int) $user->id);

        $token = Str::random(64);
        $code = (string) random_int(100000, 999999);
        $ttl = self::ttl();

        $payload = [
            'user_id' => $user->id,
            'email' => $user->email,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'resend_count' => 0,
        ];

        Cache::put(self::cacheKey($token), $payload, $ttl);
        Cache::put(self::userKey((int) $user->id), $token, $ttl);

        try {
            $user->notify(new LoginVerificationCodeNotification($code, (int) max(1, (int) ceil($ttl / 60))));
        } catch (\Throwable $exception) {
            // Local/testing: keep the challenge and surface the code in logs so
            // SMTP outages do not block sign-in during development.
            if (app()->environment(['local', 'testing'])) {
                Log::warning('Login verification email failed; using logged code for local sign-in', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'code' => $code,
                    'error' => $exception->getMessage(),
                ]);

                return [
                    'challenge_token' => $token,
                    'expires_in' => $ttl,
                ];
            }

            self::forget($token);
            Log::error('Login verification email failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        return [
            'challenge_token' => $token,
            'expires_in' => $ttl,
        ];
    }

    public static function get(string $token): ?array
    {
        $payload = Cache::get(self::cacheKey($token));

        return is_array($payload) ? $payload : null;
    }

    public static function put(string $token, array $payload): void
    {
        $ttl = self::ttl();
        Cache::put(self::cacheKey($token), $payload, $ttl);
        if (! empty($payload['user_id'])) {
            Cache::put(self::userKey((int) $payload['user_id']), $token, $ttl);
        }
    }

    public static function forget(string $token): void
    {
        $payload = self::get($token);
        Cache::forget(self::cacheKey($token));
        if (is_array($payload) && ! empty($payload['user_id'])) {
            $current = Cache::get(self::userKey((int) $payload['user_id']));
            if ($current === $token) {
                Cache::forget(self::userKey((int) $payload['user_id']));
            }
        }
    }

    public static function forgetForUser(int $userId): void
    {
        $token = Cache::pull(self::userKey($userId));
        if (is_string($token) && $token !== '') {
            Cache::forget(self::cacheKey($token));
        }
    }

    public static function lock(int $userId): void
    {
        Cache::put(self::lockKey($userId), now()->getTimestamp() + self::lockoutSeconds(), self::lockoutSeconds());
    }

    public static function lockoutMessage(int $userId): ?string
    {
        $until = Cache::get(self::lockKey($userId));
        if (! $until) {
            return null;
        }

        $remaining = max(1, (int) $until - now()->getTimestamp());

        return 'Too many invalid codes. Try again in ' . (int) ceil($remaining / 60) . ' minutes.';
    }

    public static function clearLockout(int $userId): void
    {
        Cache::forget(self::lockKey($userId));
    }

    public static function matches(array $payload, string $code): bool
    {
        return hash_equals((string) ($payload['code_hash'] ?? ''), hash('sha256', $code));
    }

    private static function lockoutSeconds(): int
    {
        return (int) config('adams.two_factor_lockout_seconds', 900);
    }

    private static function cacheKey(string $token): string
    {
        return 'login_challenge:' . $token;
    }

    private static function userKey(int $userId): string
    {
        return 'login_challenge_user:' . $userId;
    }

    private static function lockKey(int $userId): string
    {
        return 'login_2fa_lockout:' . $userId;
    }
}
