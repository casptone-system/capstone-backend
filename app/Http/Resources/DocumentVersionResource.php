<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
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
            'documentId' => $this->document_id,
            'version' => $this->version,
            'filePath' => $this->file_path,
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'fileSize' => $this->file_size,
            'uploadedBy' => $this->uploaded_by,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'uploader' => $this->whenLoaded('uploader', fn () => new UserResource($this->uploader)),
        ];
    }
}