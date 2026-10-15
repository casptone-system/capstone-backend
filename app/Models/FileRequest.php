<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FileRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_FULFILLED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'requester_program_id',
        'requester_user_id',
        'recipient_program_id',
        'recipient_user_id',
        'title',
        'description',
        'needed_by',
        'related_area_id',
        'status',
        'decline_reason',
        'fulfilled_at',
        'needed_by_reminded_at',
        'open_key',
    ];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'fulfilled_at' => 'datetime',
            'needed_by_reminded_at' => 'datetime',
        ];
    }

    public function requesterProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'requester_program_id');
    }

    public function recipientProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'recipient_program_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function relatedArea(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'related_area_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FileRequestFile::class);
    }

    public function scopeWithParties(Builder $query): Builder
    {
        return $query->with([
            'requesterProgram.college',
            'requesterProgram.chairUser',
            'recipientProgram.college',
            'recipientProgram.chairUser',
            'files.uploader',
            'relatedArea',
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
