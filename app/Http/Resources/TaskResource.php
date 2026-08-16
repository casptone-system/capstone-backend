<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'areaId' => $this->area_id,
            'accreditationCycleId' => $this->accreditation_cycle_id,
            'programId' => $this->program_id,
            'requirementId' => $this->requirement_id,
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'returnReason' => $this->return_reason,
            'priority' => $this->priority,
            'status' => $this->status,
            'dueDate' => $this->due_date?->toDateString(),
            'deadline' => $this->deadline?->toDateString(),
            'createdBy' => $this->created_by,
            'assignedBy' => $this->assigned_by,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'area' => $this->whenLoaded('area', fn () => new AccreditationAreaResource($this->area)),
            'cycle' => $this->whenLoaded('cycle', fn () => new AccreditationCycleResource($this->cycle)),
            'program' => $this->whenLoaded('program', fn () => new ProgramResource($this->program)),
            'requirement' => $this->whenLoaded('requirement', fn () => new AccreditationRequirementResource($this->requirement)),
            'creator' => $this->whenLoaded('creator', fn () => new UserResource($this->creator)),
            'assignee' => $this->whenLoaded('assignedBy', fn () => new UserResource($this->assignedBy)),
            'assignments' => $this->whenLoaded('assignments', fn () => TaskAssignmentResource::collection($this->assignments)),
        ];
    }
}