<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isChair = $user && (int) $this->chair_id === (int) $user->id;
        $isMember = $user && $this->relationLoaded('members')
            ? $this->members->contains(fn ($member) => (int) $member->user_id === (int) $user->id)
            : (bool) $user?->isAssignedToArea($this->resource);
        $assignmentRole = $isChair ? 'chair' : ($isMember ? 'member' : null);
        $review = $this->relationLoaded('reviews')
            ? $this->reviews->sortByDesc('id')->first()
            : $this->reviews()->latest('id')->first();
        $reviewStatus = $review?->current_status;
        $canSubmit = $isChair && (
            $reviewStatus === null
            || in_array($reviewStatus, ['Draft', 'Revision Requested'], true)
        );

        return [
            'id' => $this->id,
            'cycleId' => $this->cycle_id,
            'programId' => $this->cycle?->program_id,
            'name' => $this->name,
            'code' => $this->code,
            'label' => $this->sidebarLabel(),
            'status' => $this->status,
            'deadline' => $this->deadline?->toDateTimeString(),
            'assignmentRole' => $assignmentRole,
            'canUpload' => $isChair,
            'canSubmit' => $canSubmit,
            'reviewStatus' => $reviewStatus,
            'progressPercent' => (int) ($this->progress_percent ?? 0),
            'chair' => $this->whenLoaded('chair', fn () => $this->chair ? new UserResource($this->chair) : null),
            'cycle' => $this->whenLoaded('cycle', fn () => new AccreditationCycleResource($this->cycle)),
        ];
    }
}
