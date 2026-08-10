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
            return $review->cycle->program_id === $user->getEffectiveProgramId();
        }

        if ($user->isDean()) {
            return $review->cycle->program?->college_id === $user->getEffectiveCollegeId();
        }

        if ($user->isQA() || $user->isVPAA()) {
            return true;
        }

        return false;
    }

    public function submit(User $user, Review $review): bool
    {
        if ($review->submitted_by !== $user->id) {
            return false;
        }

        // Allow initial submit from Draft and resubmission from Revision Requested
        return in_array($review->current_status, ['Draft', 'Revision Requested'], true) && $user->isFaculty();
    }

    public function approve(User $user, Review $review): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($review->current_status) {
            'Submitted' => $user->isAreaIncharge() && $user->isAssignedToArea($review->area),
            'Area Approved' => $user->isProgramChair() && $this->belongsToProgram($user, $review),
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
        $programId = $user->getEffectiveProgramId();

        if (! $programId) {
            return false;
        }

        return (int) $review->cycle?->program_id === (int) $programId;
    }
}
