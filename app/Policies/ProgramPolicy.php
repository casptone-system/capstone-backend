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
            return $program->college_id === $user->college_id;
        }

        if ($user->isProgramChair()) {
            return $program->chair_id === $user->id
                || $user->belongsToProgram($program->id);
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
            return $user->belongsToProgram($program->id);
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
            return $program->college_id === $user->college_id;
        }

        // Allow program chair to update their own program's accreditation setup
        if ($user->isProgramChair()) {
            return $program->chair_id === $user->id;
        }

        return false;
    }

    protected function hasProgramMembership(User $user, Program $program): bool
    {
        return $user->belongsToProgram($program->id);
    }
}
