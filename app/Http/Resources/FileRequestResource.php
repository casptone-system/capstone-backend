<?php

namespace App\Http\Resources;

use App\Models\FileRequest;
use App\Models\FileRequestFile;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FileRequest */
class FileRequestResource extends JsonResource
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
            'description' => $this->description,
            'neededBy' => $this->needed_by?->toDateString(),
            'status' => $this->status,
            'declineReason' => $this->decline_reason,
            'fulfilledAt' => $this->fulfilled_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'relatedAreaId' => $this->related_area_id,
            'relatedAreaName' => $this->relatedArea?->name,
            'fromProgram' => $this->programPayload($this->requesterProgram),
            'toProgram' => $this->programPayload($this->recipientProgram),
            'files' => $this->files->map(fn (FileRequestFile $file) => [
                'id' => $file->id,
                'originalName' => $file->original_name,
                'mimeType' => $file->mime_type,
                'fileSize' => $file->file_size,
                'uploadedAt' => $file->created_at?->toIso8601String(),
            ])->values(),
            'canCancel' => (bool) ($user && $user->can('cancel', $this->resource)),
            'canFulfill' => (bool) ($user && $user->can('fulfill', $this->resource)),
            'canDecline' => (bool) ($user && $user->can('decline', $this->resource)),
            'canDownload' => (bool) ($user && $user->can('download', $this->resource)),
            'canRemoveFiles' => (bool) ($user && $user->can('removeFile', $this->resource)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function programPayload(?Program $program): ?array
    {
        if (! $program) {
            return null;
        }

        return [
            'id' => $program->id,
            'name' => $program->name,
            'code' => $program->code,
            'collegeName' => $program->college?->name,
            'chairName' => $program->chair_id ? $program->chairUser?->name : null,
        ];
    }
}
