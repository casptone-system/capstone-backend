<?php

namespace App\Policies;

use App\Models\Program;
use App\Models\User;

class ProgramPolicy
{
    public function viewAny(User $user, mixed $model = null): bool
    {
        return true;
    }

    public function view(User $user, Program $program): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isQA() || $user->isVPAA()) {
            return true;
        }

        if ($user->isDean()) {
            return $program->college_id === $user->getEffectiveCollegeId();
        }

        if ($user->isProgramChair()) {
            return $this->hasProgramMembership($user, $program) || $program->id === $user->getEffectiveProgramId();
        }

        if ($user->isAreaIncharge()) {
            return $program->accreditationCycles()
                ->whereHas('areas', function ($query) use ($user): void {
                    $query->where('chair_id', $user->id)
                        ->orWhereHas('members', function ($memberQuery) use ($user): void {
                            $memberQuery->where('user_id', $user->id);
                        });
                })
                ->exists();
        }

        if ($user->isFaculty()) {
            return $this->hasProgramMembership($user, $program);
        }

        return false;
    }

    public function create(User $user, mixed $model = null): bool
    {
        return $user->isSuperAdmin() || $user->isDean();
    }

    public function update(User $user, Program $program): bool
    {
        return $this->manageProgram($user, $program);
    }

    public function delete(User $user, Program $program): bool
    {
        return $this->manageProgram($user, $program);
    }

    protected function manageProgram(User $user, Program $program): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isDean()) {
            return $program->college_id === $user->getEffectiveCollegeId();
        }

        return false;
    }

    protected function hasProgramMembership(User $user, Program $program): bool
    {
        return $user->programMemberships()->where('program_id', $program->id)->exists()
            || $user->getEffectiveProgramId() === $program->id;
    }
}
