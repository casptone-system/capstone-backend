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
     * Accreditation workflow status values.
     * Tracks the handoff state: VPAA → Dean → PC → Faculty
     */
    public const WORKFLOW_STATUSES = [
        'Initial Notice',
        'Dean Acknowledged',
        'Forwarded to Chair',
        'Requirements Set',
        'Faculty Assignment',
        'Evidence Submitted',
        'Dean Validated',
        'VPAA Monitoring',
        'Ready',
        'At Risk',
    ];

    /**
     * Accreditation phases (deprecated, use WORKFLOW_STATUSES).
     * @deprecated Use WORKFLOW_STATUSES instead
     */
    public const PHASES = self::WORKFLOW_STATUSES;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'program_id',
        'college_id',
        'instrument_id',
        'level',
        'status',
        'phase',
        'workflow_status',
        'instrument_name',
        'valid_until',
        'scheduled_visit',
        'remarks',
        'acknowledged_by',
        'acknowledged_at',
        'forwarded_by',
        'forwarded_at',
        'program_chair_id',
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
            'acknowledged_at' => 'datetime',
            'forwarded_at' => 'datetime',
        ];
    }

    /**
     * Get the program that owns the accreditation cycle.
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(AccreditationInstrument::class, 'instrument_id');
    }

    /**
     * Get the areas for the accreditation cycle.
     */
    public function areas(): HasMany
    {
        return $this->hasMany(AccreditationArea::class, 'cycle_id');
    }

    /**
     * Dashboard-facing status derived from cycle status.
     * Display-layer only — not stored in the database.
     */
    public const DISPLAY_STATUSES = [
        'Accredited',
        'In Progress',
        'Not Started',
        'Expired',
    ];

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

    public function getDisplayStatusAttribute(): string
    {
        return self::mapDisplayStatus($this->status);
    }

    public static function mapDisplayStatus(?string $status): string
    {
        return match ($status) {
            'Completed' => 'Accredited',
            'Preparation', 'Internal Review', 'Ready' => 'In Progress',
            'Expired' => 'Expired',
            'Planning' => 'Not Started',
            default => 'Not Started',
        };
    }
}
