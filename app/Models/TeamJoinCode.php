<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamJoinCode extends Model
{
    public const TTL_MINUTES = 10;

    protected $fillable = [
        'team_id',
        'program_id',
        'code',
        'created_by',
        'expires_at',
        'used_by',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function isUsed(): bool
    {
        return $this->used_by !== null || $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return ! $this->isUsed() && $this->expires_at !== null && ! $this->expires_at->isFuture();
    }
}
