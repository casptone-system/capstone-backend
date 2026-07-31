<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationCycle extends Model
{
    use HasFactory;

    /**
     * Accreditation levels.
     */
    public const LEVELS = [
        'Level I',
        'Level II',
        'Level III',
        'Level IV',
    ];

    /**
     * Accreditation cycle statuses.
     */
    public const STATUSES = [
        'Planning',
        'Preparation',
        'Internal Review',
        'Ready',
        'Completed',
        'Expired',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'program_id',
        'level',
        'status',
        'valid_until',
        'scheduled_visit',
        'remarks',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'scheduled_visit' => 'date',
        ];
    }

    /**
     * Get the program that owns the accreditation cycle.
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * Get the areas for the accreditation cycle.
     */
    public function areas(): HasMany
    {
        return $this->hasMany(AccreditationArea::class, 'cycle_id');
    }

    /**
     * Get the readiness label derived from the status.
     */
    public function getReadinessAttribute(): string
    {
        return match ($this->status) {
            'Planning' => 'Not Ready',
            'Preparation' => 'In Progress',
            'Internal Review' => 'In Review',
            'Ready' => 'Ready',
            'Completed' => 'Completed',
            'Expired' => 'Expired',
            default => $this->status,
        };
    }
}
