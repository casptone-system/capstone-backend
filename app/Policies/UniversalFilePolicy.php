<?php

namespace App\Policies;

use App\Models\UniversalFile;
use App\Models\User;

class UniversalFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $this->chairsAProgram($user);
    }

    public function view(User $user, UniversalFile $universalFile): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, UniversalFile $universalFile): bool
    {
        return $this->canManage($user);
    }

    public function download(User $user, UniversalFile $universalFile): bool
    {
        return $this->viewAny($user);
    }

    private function canManage(User $user): bool
    {
        return $user->isQA() || $user->isSuperAdmin();
    }

    private function chairsAProgram(User $user): bool
    {
        return $user->isProgramChair() && $user->chairedProgramId() !== null;
    }
}
