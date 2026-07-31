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
            'cycleId' => $this->cycle_id,
            'name' => $this->name,
            'description' => $this->description,
            'chairId' => $this->chair_id,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'chair' => $this->whenLoaded('chair', fn () => new UserResource($this->chair)),
            'members' => $this->whenLoaded('members', fn () => AreaMemberResource::collection($this->members)),
            'cycle' => $this->whenLoaded('cycle', fn () => new AccreditationCycleResource($this->cycle)),
        ];
    }
}