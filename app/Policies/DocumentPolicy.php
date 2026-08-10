<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isFaculty()) {
            return $document->uploaded_by === $user->id;
        }

        if ($user->isAreaIncharge()) {
            return $user->isAssignedToArea($document->area);
        }

        if ($user->isProgramChair()) {
            return $document->program_id === $user->getEffectiveProgramId();
        }

        if ($user->isDean()) {
            return $document->program?->college_id === $user->getEffectiveCollegeId();
        }

        if ($user->isQA() || $user->isVPAA()) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isFaculty() || $user->isAreaIncharge() || $user->isProgramChair();
    }

    public function update(User $user, Document $document): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isFaculty()) {
            return $document->uploaded_by === $user->id;
        }

        if ($user->isAreaIncharge()) {
            return $user->isAssignedToArea($document->area);
        }

        if ($user->isProgramChair()) {
            return $document->program_id === $user->getEffectiveProgramId();
        }

        return false;
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function replace(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }
}
