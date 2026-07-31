<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewCommentResource extends JsonResource
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
            'reviewId' => $this->review_id,
            'userId' => $this->user_id,
            'role' => $this->role,
            'action' => $this->action,
            'fromStatus' => $this->from_status,
            'toStatus' => $this->to_status,
            'comment' => $this->comment,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
        ];
    }
}