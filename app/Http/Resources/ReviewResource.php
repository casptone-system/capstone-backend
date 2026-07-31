<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
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
            'cycleId' => $this->cycle_id,
            'currentStatus' => $this->current_status,
            'submittedBy' => $this->submitted_by,
            'submittedAt' => $this->submitted_at?->toDateTimeString(),
            'completedAt' => $this->completed_at?->toDateTimeString(),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'expectedReviewerRole' => $this->getExpectedReviewerRole(),
            'isTerminal' => $this->isTerminal(),
            'area' => $this->whenLoaded('area', fn () => new AccreditationAreaResource($this->area)),
            'cycle' => $this->whenLoaded('cycle', fn () => new AccreditationCycleResource($this->cycle)),
            'submitter' => $this->whenLoaded('submitter', fn () => new UserResource($this->submitter)),
            'comments' => $this->whenLoaded('comments', fn () => ReviewCommentResource::collection(
                $this->comments->sortByDesc('created_at')
            )),
        ];
    }
}