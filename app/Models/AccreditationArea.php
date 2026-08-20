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
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'cycle_id',
        'instrument_id',
        'name',
        'description',
        'chair_id',
        'status',
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
}
