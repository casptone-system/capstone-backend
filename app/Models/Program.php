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
     * Return the effective chair name from the related user or legacy text field.
     */
    public function getChairNameAttribute(): ?string
    {
        return $this->chairUser?->name ?? $this->attributes['chair'] ?? null;
    }

    /**
     * Get the active program membership records.
     */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(ProgramMember::class)->whereNull('ended_at');
    }

    /**
     * Get the accreditation cycles for the program.
     */
    public function accreditationCycles(): HasMany
    {
        return $this->hasMany(AccreditationCycle::class);
    }
}
