<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyApiEmailNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

// DISABLED: Email verification — temporarily off for dev, see [2026-08-18]
// class User extends Authenticatable implements \Illuminate\Contracts\Auth\MustVerifyEmail
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    // DISABLED: Email verification — temporarily off for dev, see [2026-08-18]
    // Changed from: use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes, MustVerifyEmail;
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'password',
        'phone_number',
        'birth_date',
        'profile_photo',
        'program_id',
        'team_id',
        'college_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Get the tasks created by the user.
     */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function college()
    {
        return $this->belongsTo(College::class, 'college_id');
    }

    /**
     * Get the task assignments for the user.
     */
    public function taskAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'user_id');
    }

    public function programMemberships()
    {
        return $this->hasMany(ProgramMember::class, 'user_id');
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'program_members');
    }

    /**
     * Get storage files owned by the user
     */
    public function storageFiles(): HasMany
    {
        return $this->hasMany(RoleStorageFile::class, 'user_id');
    }

    public function invitationsSent()
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    /**
     * Get the documents uploaded by the user.
     */
    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function hasCanonicalRole(string $role): bool
    {
        $normalizedRole = $this->normalizeRoleName($role);

        return $this->getRoleNames()
            ->map(fn (string $name) => $this->normalizeRoleName($name))
            ->contains($normalizedRole);
    }

    public function hasAnyCanonicalRole(array $roles): bool
    {
        $normalizedRoles = array_map([$this, 'normalizeRoleName'], $roles);

        return $this->getRoleNames()
            ->map(fn (string $name) => $this->normalizeRoleName($name))
            ->intersect($normalizedRoles)
            ->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasCanonicalRole('superadmin');
    }

    public function isQA(): bool
    {
        return $this->hasCanonicalRole('qa');
    }

    public function isVPAA(): bool
    {
        return $this->hasCanonicalRole('vpaa/di') || $this->hasCanonicalRole('vpaa');
    }

    public function isDean(): bool
    {
        return $this->hasCanonicalRole('dean');
    }

    public function isProgramChair(): bool
    {
        return $this->hasCanonicalRole('program-chair');
    }

    public function isAreaIncharge(): bool
    {
        return $this->hasCanonicalRole('area-incharge');
    }

    public function isFaculty(): bool
    {
        return $this->hasCanonicalRole('faculty');
    }

    public function getEffectiveProgramId(): ?int
    {
        if ($this->program_id) {
            return (int) $this->program_id;
        }

        if ($this->team?->program_id) {
            return (int) $this->team->program_id;
        }

        $chairedProgramId = Program::where('chair_id', $this->id)->value('id');
        if ($chairedProgramId) {
            return (int) $chairedProgramId;
        }

        $membershipProgramId = $this->programMemberships()->orderByDesc('id')->value('program_id');

        return $membershipProgramId ? (int) $membershipProgramId : null;
    }

    public function assignedProgram(): ?Program
    {
        if ($this->getEffectiveProgramId()) {
            return Program::find($this->getEffectiveProgramId());
        }

        return Program::where('chair_id', $this->id)->first();
    }

    public function assignedProgramId(): ?int
    {
        return $this->assignedProgram()?->id;
    }

    public function ownsAssignedProgram(int $programId): bool
    {
        return (int) $this->assignedProgramId() === (int) $programId
            || Program::where('id', $programId)->where('chair_id', $this->id)->exists();
    }

    public function belongsToProgram(int $programId): bool
    {
        if ((int) $this->program_id === (int) $programId) {
            return true;
        }

        if ((int) $this->team?->program_id === (int) $programId) {
            return true;
        }

        return $this->programMemberships()->where('program_id', $programId)->exists();
    }

    public function getEffectiveCollegeId(): ?int
    {
        if ($this->college_id) {
            return $this->college_id;
        }

        $programId = $this->getEffectiveProgramId();
        if (! $programId) {
            return null;
        }

        return Program::find($programId)?->college_id;
    }

    public function isAssignedToArea(AccreditationArea $area): bool
    {
        if ($this->isChairOfArea($area)) {
            return true;
        }

        return AreaMember::where('area_id', $area->id)
            ->where('user_id', $this->id)
            ->exists();
    }

    public function isChairOfArea(?AccreditationArea $area): bool
    {
        return $area !== null && (int) $area->chair_id === (int) $this->id;
    }

    /**
     * Faculty / Area Chair / members are locked to the program's active cycle.
     * QA, VPAA, Dean, Program Chair, and SuperAdmin keep seeing every level.
     */
    public function isLockedToProgramActiveLevel(): bool
    {
        if ($this->isSuperAdmin() || $this->isQA() || $this->isVPAA() || $this->isDean() || $this->isProgramChair()) {
            return false;
        }

        return $this->isFaculty() || $this->isAreaIncharge();
    }

    public function assignedAreaIds()
    {
        return AreaMember::where('user_id', $this->id)
            ->pluck('area_id')
            ->concat(AccreditationArea::where('chair_id', $this->id)->pluck('id'))
            ->unique()
            ->values();
    }

    protected function normalizeRoleName(string $role): string
    {
        $base = trim(strtolower($role));
        $base = str_replace(['_', ' '], '-', $base);
        $base = preg_replace('/-+/', '-', $base) ?: $base;

        if (str_contains($base, 'vpaa') && str_contains($base, 'di')) {
            return 'vpaa/di';
        }

        if (str_starts_with($base, 'super') || str_contains($base, 'super-administrator') || str_contains($base, 'superadministrator')) {
            return 'superadmin';
        }

        if (str_contains($base, 'area') && (str_contains($base, 'in-charge') || str_contains($base, 'incharge'))) {
            return 'area-incharge';
        }

        if ($base === 'programchair') {
            return 'program-chair';
        }

        return $base;
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (empty($this->profile_photo)) {
            return null;
        }

        return asset('storage/' . ltrim($this->profile_photo, '/'));
    }

    // DISABLED: Email verification notification — temporarily off for dev, see [2026-08-18]
    // public function sendEmailVerificationNotification(): void
    // {
    //     $this->notify(new VerifyApiEmailNotification());
    // }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
