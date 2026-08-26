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

        if ($user->isQA() || $user->isVPAA()) {
            return true;
        }

        if ($user->isProgramChair()) {
            $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();

            if ($programId !== null && (int) $document->program_id === (int) $programId) {
                return true;
            }

            $area = $document->area ?: $document->contentRow?->parameter?->area;
            $areaProgramId = $area?->cycle?->program_id ?? $area?->cycle()->value('program_id');

            return $areaProgramId !== null && (int) $areaProgramId === (int) $programId;
        }

        if ($user->isDean()) {
            return $document->program?->college_id === $user->college_id;
        }

        if ($document->area && ($user->isFaculty() || $user->isAreaIncharge()) && $user->isAssignedToArea($document->area)) {
            return true;
        }

        if ($user->isFaculty()) {
            return $document->uploaded_by === $user->id;
        }

        if ($user->isAreaIncharge()) {
            return $document->area ? $user->isAssignedToArea($document->area) : false;
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

        if ($document->area_id || $document->content_row_id) {
            $area = $document->area ?: $document->contentRow?->parameter?->area;

            return $user->isChairOfArea($area);
        }

        if ($user->isFaculty()) {
            return $document->uploaded_by === $user->id;
        }

        if ($user->isAreaIncharge()) {
            return $document->area ? $user->isAssignedToArea($document->area) : false;
        }

        if ($user->isProgramChair()) {
            return $document->program_id === $user->assignedProgramId();
        }

        if ($user->isDean()) {
            return $document->program?->college_id === $user->college_id;
        }

        return false;
    }

    public function approve(User $user, Document $document): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isProgramChair() && $this->belongsToAssignedProgram($user, $document);
    }

    public function requestRevision(User $user, Document $document): bool
    {
        return $this->approve($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    protected function belongsToAssignedProgram(User $user, Document $document): bool
    {
        $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();

        if (! $programId) {
            return false;
        }

        if ($document->program_id !== null && (int) $document->program_id === (int) $programId) {
            return true;
        }

        $document->loadMissing(['area.cycle', 'contentRow.parameter.area.cycle']);
        $area = $document->area ?: $document->contentRow?->parameter?->area;
        $areaProgramId = $area?->cycle?->program_id;

        return $areaProgramId !== null && (int) $areaProgramId === (int) $programId;
    }

    public function replace(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }
}
