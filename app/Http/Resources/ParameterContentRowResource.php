<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParameterContentRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->relationLoaded('status') ? $this->status : $this->status()->first();

        return [
            'id' => $this->id,
            'parameterId' => $this->parameter_id,
            'content' => $this->content,
            'sortOrder' => $this->sort_order,
            'isDone' => (bool) ($status?->is_done ?? false),
            'doneAt' => $status?->done_at?->toDateTimeString(),
            'doneBy' => $status?->relationLoaded('doneBy') && $status->doneBy
                ? new UserResource($status->doneBy)
                : null,
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
