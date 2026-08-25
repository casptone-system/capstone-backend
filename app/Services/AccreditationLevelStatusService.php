<?php

namespace App\Services;

use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AccreditationLevelStatusService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(User $user, ?string $view = null): Collection
    {
        $view = $this->normalizeView($view, $user);

        if (! $this->userCanUseView($user, $view)) {
            throw new InvalidArgumentException('You are not allowed to view accreditation status for this dashboard.');
        }

        $programs = $this->visiblePrograms($user, $view)
            ->with(['college', 'accreditationCycles'])
            ->orderBy('name')
            ->get();

        return $programs->map(fn (Program $program) => $this->serializeProgram($program))->values();
    }

    public function normalizeView(?string $view, User $user): string
    {
        $requested = strtolower(str_replace(['_', ' '], '-', trim((string) $view)));

        $allowed = [
            'superadmin',
            'vpaa',
            'qa',
            'dean',
            'program-chair',
            'area-incharge',
            'faculty',
        ];

        if (in_array($requested, $allowed, true)) {
            return $requested;
        }

        return match (true) {
            $user->isSuperAdmin() => 'superadmin',
            $user->isVPAA() => 'vpaa',
            $user->isQA() => 'qa',
            $user->isDean() => 'dean',
            $user->isProgramChair() => 'program-chair',
            $user->isAreaIncharge() => 'area-incharge',
            default => 'faculty',
        };
    }

    public function userCanUseView(User $user, string $view): bool
    {
        return match ($view) {
            'superadmin' => $user->isSuperAdmin(),
            'vpaa' => $user->isVPAA(),
            'qa' => $user->isQA(),
            'dean' => $user->isDean(),
            'program-chair' => $user->isProgramChair(),
            'area-incharge' => $user->isAreaIncharge(),
            'faculty' => $user->isFaculty() || $user->isAreaIncharge(),
            default => false,
        };
    }

    private function visiblePrograms(User $user, string $view): Builder
    {
        $query = Program::query();

        return match ($view) {
            'superadmin', 'vpaa', 'qa' => $query,
            'dean' => $query->where('college_id', $user->college_id ?: 0),
            'program-chair' => $this->scopeToProgramChair($query, $user),
            'area-incharge' => $this->scopeToAssignedAreas($query, $user),
            default => $this->scopeToFacultyProgram($query, $user),
        };
    }

    private function scopeToProgramChair(Builder $query, User $user): Builder
    {
        $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();

        return $query->where(function (Builder $inner) use ($user, $programId) {
            $inner->where('chair_id', $user->id);

            if ($programId) {
                $inner->orWhere('id', $programId);
            }
        });
    }

    private function scopeToFacultyProgram(Builder $query, User $user): Builder
    {
        $programId = $user->getEffectiveProgramId();

        if (! $programId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('id', $programId);
    }

    private function scopeToAssignedAreas(Builder $query, User $user): Builder
    {
        return $query->whereHas('accreditationCycles.areas', function (Builder $areas) use ($user) {
            $areas->where('chair_id', $user->id)
                ->orWhereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProgram(Program $program): array
    {
        $latestByLevel = $program->accreditationCycles
            ->sortByDesc('created_at')
            ->unique(fn (AccreditationCycle $cycle) => $cycle->level);

        $levels = collect(AccreditationCycle::LEVELS)->map(function (string $level) use ($latestByLevel) {
            $cycle = $latestByLevel->firstWhere('level', $level);

            return [
                'level' => $level,
                'cycleId' => $cycle?->id,
                'cycleStatus' => $cycle?->status,
                'preparationStatus' => $cycle?->preparation_status,
                'displayStatus' => $cycle?->display_status ?? 'Not Started',
                'validUntil' => $cycle?->valid_until?->toDateString(),
                'validityStatus' => $cycle?->validity_status ?? 'Not set',
                'scheduledVisit' => $cycle?->scheduled_visit?->toDateString(),
            ];
        })->all();

        return [
            'programId' => $program->id,
            'programName' => $program->name,
            'programCode' => $program->code,
            'collegeId' => $program->college_id,
            'collegeName' => $program->college?->name,
            'levels' => $levels,
        ];
    }
}
