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
            'program_id' => $this->program_id,
            'college_id' => $this->college_id,
            'instrument_id' => $this->instrument_id,
            'programId' => $this->program_id,
            'collegeId' => $this->college_id,
            'instrumentId' => $this->instrument_id,
            'level' => $this->level,
            'status' => $this->status,
            'phase' => $this->phase,
            'workflow_status' => $this->workflow_status,
            'instrument_name' => $this->instrument_name,
            'instrumentName' => $this->instrument_name,
            'valid_until' => $this->valid_until?->toDateString(),
            'scheduled_visit' => $this->scheduled_visit?->toDateString(),
            'validUntil' => $this->valid_until?->toDateString(),
            'scheduledVisit' => $this->scheduled_visit?->toDateString(),
            'remarks' => $this->remarks,
            'acknowledged_by' => $this->acknowledged_by,
            'acknowledged_at' => $this->acknowledged_at?->toDateTimeString(),
            'acknowledgedBy' => $this->acknowledged_by,
            'acknowledgedAt' => $this->acknowledged_at?->toDateTimeString(),
            'forwarded_by' => $this->forwarded_by,
            'forwarded_at' => $this->forwarded_at?->toDateTimeString(),
            'program_chair_id' => $this->program_chair_id,
            'forwardedBy' => $this->forwarded_by,
            'forwardedAt' => $this->forwarded_at?->toDateTimeString(),
            'programChairId' => $this->program_chair_id,
            'readiness' => $this->readiness,
            'displayStatus' => $this->display_status,
            'display_status' => $this->display_status,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'program' => $this->whenLoaded('program', fn () => new ProgramResource($this->program)),
            'instrument' => $this->whenLoaded('instrument', fn () => new AccreditationInstrumentResource($this->instrument)),
        ];
    }
}
