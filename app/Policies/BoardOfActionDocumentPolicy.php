<?php

namespace App\Policies;

use App\Models\BoardOfActionDocument;
use App\Models\User;

class BoardOfActionDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, BoardOfActionDocument $document): bool
    {
        if ($this->canManage($user)) {
            return true;
        }

        $chairId = $document->program()->value('chair_id');

        return $chairId !== null && (int) $chairId === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, BoardOfActionDocument $document): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, BoardOfActionDocument $document): bool
    {
        return $this->canManage($user);
    }

    public function download(User $user, BoardOfActionDocument $document): bool
    {
        return $this->view($user, $document);
    }

    private function canManage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isQA();
    }
}
