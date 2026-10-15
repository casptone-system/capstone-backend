<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardOfActionDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latest = $this->relationLoaded('latestVersion')
            ? $this->latestVersion
            : $this->latestVersion()->first();

        return [
            'id' => $this->id,
            'programId' => $this->program_id,
            'title' => $this->title,
            'displayTitle' => $this->displayTitle(),
            'onFileDate' => $this->onFileDate(),
            'notes' => $this->notes,
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'currentVersion' => $this->current_version,
            'originalName' => $latest?->original_name,
            'mimeType' => $latest?->mime_type,
            'fileSize' => $latest?->file_size,
        ];
    }
}
