<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccreditationAreaResource extends JsonResource
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
            'cycle_id' => $this->cycle_id,
            'instrument_id' => $this->instrument_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'chairId' => $this->chair_id,
            'deadline' => $this->deadline?->toDateTimeString(),
            'status' => $this->status,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'chair' => $this->whenLoaded('chair', fn () => new UserResource($this->chair)),
            'members' => $this->whenLoaded('members', fn () => AreaMemberResource::collection($this->members)),
            'requirements' => AccreditationRequirementResource::collection($this->whenLoaded('requirements')),
            'cycle' => $this->whenLoaded('cycle', fn () => new AccreditationCycleResource($this->cycle)),
        ];
    }
}