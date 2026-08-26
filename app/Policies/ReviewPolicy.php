<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function view(User $user, Review $review): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isFaculty()) {
            return $review->submitted_by === $user->id;
        }

        if ($user->isAreaIncharge()) {
            return $user->isAssignedToArea($review->area);
        }

        if ($user->isProgramChair()) {
            return $review->cycle->program_id === $user->assignedProgramId();
        }

        if ($user->isDean()) {
            return $review->cycle->program?->college_id === $user->college_id;
        }

        if ($user->isQA() || $user->isVPAA()) {
            return true;
        }

        return false;
    }

    public function submit(User $user, Review $review): bool
    {
        if (! in_array($review->current_status, ['Draft', 'Revision Requested'], true)) {
            return false;
        }

        if ($user->isChairOfArea($review->area)) {
            return true;
        }

        return $review->submitted_by === $user->id && $user->isFaculty();
    }

    public function approve(User $user, Review $review): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($review->current_status) {
            'Submitted', 'Area Approved' => $user->isProgramChair() && $this->belongsToProgram($user, $review),
            default => false,
        };
    }

    public function requestRevision(User $user, Review $review): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->approve($user, $review);
    }

    public function reject(User $user, Review $review): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->approve($user, $review);
    }

    protected function belongsToProgram(User $user, Review $review): bool
    {
        $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();

        if (! $programId) {
            return false;
        }

        return (int) $review->cycle?->program_id === (int) $programId;
    }

    protected function belongsToCollege(User $user, Review $review): bool
    {
        $collegeId = $user->college_id;

        if (! $collegeId) {
            return false;
        }

        return (int) $review->cycle?->program?->college_id === (int) $collegeId;
    }
}
