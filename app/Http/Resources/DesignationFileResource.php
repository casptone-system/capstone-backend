<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignationFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'role_label' => $this->role_label,
            'area_label' => $this->area_label,
            'program_name' => $this->program_name,
            'level' => $this->level,
            'place_name' => $this->place_name,
            'signer_name' => $this->signer_name,
            'designation_date' => $this->designation_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'filename' => $this->original_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

