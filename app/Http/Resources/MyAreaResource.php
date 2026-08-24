<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $assignmentRole = (int) $this->chair_id === (int) $user?->id ? 'chair' : 'member';

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
            'canUpload' => $assignmentRole === 'chair',
            'progressPercent' => (int) ($this->progress_percent ?? 0),
            'chair' => $this->whenLoaded('chair', fn () => $this->chair ? new UserResource($this->chair) : null),
            'cycle' => $this->whenLoaded('cycle', fn () => new AccreditationCycleResource($this->cycle)),
        ];
    }
}
