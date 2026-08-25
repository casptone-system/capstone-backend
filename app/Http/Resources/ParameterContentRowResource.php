<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParameterContentRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->relationLoaded('status') ? $this->status : $this->status()->first();
        $documents = collect(
            $this->relationLoaded('documents')
                ? $this->documents
                : $this->documents()->with(['versions', 'uploader'])->orderByDesc('id')->get()
        )->sortByDesc('id')->values();
        $document = $documents->first()
            ?: ($this->relationLoaded('latestDocument')
                ? $this->latestDocument
                : $this->latestDocument()->with(['versions', 'uploader'])->first());

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
            'hasFile' => $documents->isNotEmpty() || $document !== null,
            'fileCount' => $documents->count() ?: ($document ? 1 : 0),
            'document' => $document ? new DocumentResource($document) : null,
            'documents' => DocumentResource::collection($documents),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
