<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TrustedDevice extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'name',
        'ip_address',
        'user_agent',
        'last_used_at',
        'trusted_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'trusted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return array{plain_token: string, device: self}
     */
    public static function issueFor(User $user, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $plainToken = Str::random(64);
        $ttlDays = max(1, (int) config('adams.trusted_device_ttl_days', 90));
        $maxDevices = max(1, (int) config('adams.trusted_device_max_per_user', 10));

        $device = static::query()->create([
            'user_id' => $user->id,
            'token_hash' => static::hashToken($plainToken),
            'name' => static::labelFromUserAgent($userAgent),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? Str::limit($userAgent, 1000, '') : null,
            'last_used_at' => now(),
            'trusted_at' => now(),
            'expires_at' => now()->addDays($ttlDays),
        ]);

        $activeIds = static::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit($maxDevices)
            ->pluck('id');

        static::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNotIn('id', $activeIds)
            ->update(['revoked_at' => now()]);

        return [
            'plain_token' => $plainToken,
            'device' => $device,
        ];
    }

    public static function findActive(string $plainToken, User $user): ?self
    {
        if ($plainToken === '') {
            return null;
        }

        /** @var self|null $device */
        $device = static::query()
            ->where('user_id', $user->id)
            ->where('token_hash', static::hashToken($plainToken))
            ->whereNull('revoked_at')
            ->first();

        if (! $device || ! $device->isActive()) {
            return null;
        }

        return $device;
    }

    public function touchLastUsed(?string $ipAddress = null, ?string $userAgent = null): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'ip_address' => $ipAddress ?? $this->ip_address,
            'user_agent' => $userAgent
                ? Str::limit($userAgent, 1000, '')
                : $this->user_agent,
        ])->save();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    public static function revokeAllForUser(User $user): void
    {
        static::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private static function labelFromUserAgent(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        $ua = strtolower($userAgent);
        $browser = match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'chrome/') && ! str_contains($ua, 'edg/') => 'Chrome',
            str_contains($ua, 'firefox/') => 'Firefox',
            str_contains($ua, 'safari/') && ! str_contains($ua, 'chrome/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') => 'iOS',
            str_contains($ua, 'linux') => 'Linux',
            default => 'Device',
        };

        return $browser.' on '.$os;
    }
}
