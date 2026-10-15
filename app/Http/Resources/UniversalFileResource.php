<?php

namespace App\Http\Resources;

use App\Models\UniversalFile;
use App\Support\UniversalFileRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UniversalFile */
class UniversalFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'notes' => $this->notes,
            'areaCode' => $this->area_code,
            'areaName' => UniversalFileRules::nameFor($this->area_code),
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'fileSize' => $this->file_size,
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'canDelete' => (bool) ($user && $user->can('delete', $this->resource)),
        ];
    }
}
