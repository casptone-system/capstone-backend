<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'college_id',
        'name',
        'code',
        'chair',
        'chair_id',
        'active_cycle_id',
        'accreditation_status',
        'compliance_score',
        'accreditation_level',
        'accreditation_phase',
        'scheduled_visit',
        'valid_until',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'compliance_score' => 'integer',
            'scheduled_visit' => 'date',
            'valid_until' => 'date',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'chair_name',
        'needs_chair_assigned',
    ];

    /**
     * Get the college that owns the program.
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * Get the current program chair user.
     */
    public function chairUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chair_id');
    }

    /**
     * Return the chair's real user name. Legacy faker `chair` strings are not displayed.
     */
    public function getChairNameAttribute(): ?string
    {
        if ($this->chair_id) {
            return $this->chairUser?->name;
        }

        return null;
    }

    public function getNeedsChairAssignedAttribute(): bool
    {
        return empty($this->chair_id);
    }

    /**
     * Get the program membership records.
     */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(ProgramMember::class);
    }

    /**
     * Get the accreditation cycles for the program.
     */
    public function accreditationCycles(): HasMany
    {
        return $this->hasMany(AccreditationCycle::class);
    }

    /**
     * Visibility/routing cycle Faculty and area members are locked to.
     */
    public function activeCycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationCycle::class, 'active_cycle_id');
    }
}
