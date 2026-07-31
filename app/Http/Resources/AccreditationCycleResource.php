<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccreditationCycleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'programId' => $this->program_id,
            'level' => $this->level,
            'status' => $this->status,
            'validUntil' => $this->valid_until?->toDateString(),
            'scheduledVisit' => $this->scheduled_visit?->toDateString(),
            'remarks' => $this->remarks,
            'readiness' => $this->readiness,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'program' => $this->whenLoaded('program', fn () => new ProgramResource($this->program)),
        ];
    }
}
