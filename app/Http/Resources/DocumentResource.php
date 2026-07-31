<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
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
            'programId' => $this->program_id,
            'areaId' => $this->area_id,
            'taskId' => $this->task_id,
            'title' => $this->title,
            'description' => $this->description,
            'schoolYear' => $this->school_year,
            'uploadedBy' => $this->uploaded_by,
            'currentVersion' => $this->current_version,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'program' => $this->whenLoaded('program', fn () => new ProgramResource($this->program)),
            'area' => $this->whenLoaded('area', fn () => new AccreditationAreaResource($this->area)),
            'task' => $this->whenLoaded('task', fn () => new TaskResource($this->task)),
            'uploader' => $this->whenLoaded('uploader', fn () => new UserResource($this->uploader)),
            'versions' => $this->whenLoaded('versions', fn () => DocumentVersionResource::collection(
                $this->versions->sortByDesc('version')
            )),
            'latestVersion' => $this->whenLoaded('versions', function () {
                $latest = $this->versions->sortByDesc('version')->first();
                return $latest ? new DocumentVersionResource($latest) : null;
            }),
        ];
    }
}