<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationArea extends Model
{
    use HasFactory;

    /**
     * Area statuses.
     */
    public const STATUSES = [
        'Not Started',
        'In Progress',
        'Completed',
    ];

    /**
     * The 10 fixed AACCUP accreditation areas used for faculty area assignments.
     * These are static/predefined per the Feature: Area Assignment Module.
     * `code` is a stable identifier (area-1 … area-10) used for matching.
     *
     * @var list<array{code: string, name: string}>
     */
    public const AACCUP_AREAS = [
        ['code' => 'area-1',  'name' => 'Area 1 – Vision, Mission, Goals and Objectives'],
        ['code' => 'area-2',  'name' => 'Area 2 – Faculty'],
        ['code' => 'area-3',  'name' => 'Area 3 – Curriculum and Instruction'],
        ['code' => 'area-4',  'name' => 'Area 4 – Support to Students'],
        ['code' => 'area-5',  'name' => 'Area 5 – Research'],
        ['code' => 'area-6',  'name' => 'Area 6 – Extension and Community Involvement'],
        ['code' => 'area-7',  'name' => 'Area 7 – Library'],
        ['code' => 'area-8',  'name' => 'Area 8 – Physical Plant and Facilities'],
        ['code' => 'area-9',  'name' => 'Area 9 – Laboratories'],
        ['code' => 'area-10', 'name' => 'Area 10 – Administration'],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'cycle_id',
        'instrument_id',
        'name',
        'code',
        'description',
        'chair_id',
        'deadline',
        'status',
        'progress_percent',
        'progress_computed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chair_id' => 'integer',
            'deadline' => 'datetime',
            'progress_percent' => 'integer',
            'progress_computed_at' => 'datetime',
        ];
    }

    /**
     * Get the accreditation cycle that owns the area.
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationCycle::class, 'cycle_id');
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(AccreditationInstrument::class, 'instrument_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(AccreditationRequirement::class, 'area_id');
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(AccreditationParameter::class, 'area_id')->orderBy('sort_order');
    }

    /**
     * Get the chair of the area.
     */
    public function chair(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chair_id');
    }

    /**
     * Get the members of the area.
     */
    public function members(): HasMany
    {
        return $this->hasMany(AreaMember::class, 'area_id');
    }

    /**
     * Get the documents for the area.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'area_id');
    }

    /**
     * Get the tasks for the area.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'area_id');
    }

    /**
     * Get the reviews for the area.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'area_id');
    }

    public function sidebarLabel(): string
    {
        $number = null;
        if (is_string($this->code) && preg_match('/area-(\d+)/i', $this->code, $matches)) {
            $number = $matches[1];
        }

        $prettyName = trim((string) preg_replace('/^area\s*\d+\s*[–\-—:]\s*/iu', '', (string) $this->name));
        if ($prettyName === '') {
            $prettyName = trim((string) $this->name);
        }

        if ($number) {
            return $prettyName !== '' ? "AREA {$number} ({$prettyName})" : "AREA {$number}";
        }

        return strtoupper($prettyName !== '' ? $prettyName : 'AREA');
    }
}
