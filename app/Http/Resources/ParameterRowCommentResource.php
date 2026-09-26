<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParameterRowCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = $request->user()?->id;

        return [
            'id' => $this->id,
            'contentRowId' => $this->content_row_id,
            'body' => $this->body,
            'source' => $this->source,
            'authorId' => $this->author_id,
            'authorName' => $this->relationLoaded('author') ? ($this->author?->name ?? 'Former user') : null,
            'authorRole' => $this->author_role,
            'isOwn' => $userId !== null && $this->author_id !== null && (int) $this->author_id === (int) $userId,
            'createdAt' => $this->created_at?->toDateTimeString(),
        ];
    }
}
