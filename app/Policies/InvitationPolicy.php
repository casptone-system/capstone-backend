<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\Program;
use App\Models\User;

class InvitationPolicy
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

    public function view(User $user, Invitation $invitation): bool
    {
        return $this->viewAny($user, $invitation->program);
    }

    public function create(User $user, mixed $program = null): bool
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

    public function resend(User $user, Invitation $invitation): bool
    {
        return $this->create($user, $invitation->program);
    }

    public function revoke(User $user, Invitation $invitation): bool
    {
        return $this->create($user, $invitation->program);
    }

    public function accept(User $user, Invitation $invitation): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! in_array($invitation->status, ['pending', 'requested'], true)) {
            return false;
        }

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            return false;
        }

        if ($invitation->email && strtolower($invitation->email) !== strtolower($user->email)) {
            return false;
        }

        return true;
    }

    public function approve(User $user, Invitation $invitation): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($invitation->status !== 'requested' || ! $invitation->program) {
            return false;
        }

        if ($user->isDean()) {
            return $invitation->program->college_id === $user->college_id;
        }

        if ($user->isProgramChair()) {
            return $user->ownsAssignedProgram($invitation->program->id);
        }

        return false;
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
            return $program->college_id === $user->college_id;
        }

        if ($user->isProgramChair()) {
            return $user->ownsAssignedProgram($program->id);
        }

        return false;
    }
}
