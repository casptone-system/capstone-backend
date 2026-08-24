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
            'canUpload' => true,
            'progressPercent' => (int) ($this->progress_percent ?? 0),
            'chair' => $this->whenLoaded('chair', fn () => $this->chair ? new UserResource($this->chair) : null),
            'cycle' => $this->whenLoaded('cycle', fn () => new AccreditationCycleResource($this->cycle)),
        ];
    }
}
