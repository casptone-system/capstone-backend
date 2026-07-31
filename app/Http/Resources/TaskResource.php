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
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'dueDate' => $this->due_date?->toDateString(),
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'area' => $this->whenLoaded('area', fn () => new AccreditationAreaResource($this->area)),
            'creator' => $this->whenLoaded('creator', fn () => new UserResource($this->creator)),
            'assignments' => $this->whenLoaded('assignments', fn () => TaskAssignmentResource::collection($this->assignments)),
        ];
    }
}