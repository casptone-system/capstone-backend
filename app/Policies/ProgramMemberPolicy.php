<?php

namespace App\Policies;

use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\User;

class ProgramMemberPolicy
{
    public function viewAny(User $user, mixed $program = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $program = $this->resolveProgram($program);
        if ($program === null) {
            return false;
        }

        return $this->canManageProgram($user, $program);
    }

    public function view(User $user, ProgramMember $member): bool
    {
        return $this->viewAny($user, $member->program);
    }

    public function add(User $user, mixed $program = null): bool
    {
        return $this->canManageProgram($user, $program);
    }

    public function remove(User $user, ProgramMember $member): bool
    {
        return $this->canManageProgram($user, $member->program);
    }

    protected function resolveProgram(mixed $input): ?Program
    {
        if (is_array($input)) {
            $input = $input[1] ?? null;
        }

        return $input instanceof Program ? $input : null;
    }

    protected function canManageProgram(User $user, Program $program): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isDean()) {
            return $program->college_id === $user->getEffectiveCollegeId();
        }

        if ($user->isProgramChair()) {
            return $program->id === $user->getEffectiveProgramId();
        }

        return false;
    }
}
