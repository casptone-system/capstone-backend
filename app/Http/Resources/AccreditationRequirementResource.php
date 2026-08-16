<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccreditationRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'area_id' => $this->area_id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'evidence_required' => $this->evidence_required,
            'evidence_guidance' => $this->evidence_guidance,
            'required_evidence_type' => $this->required_evidence_type,
            'status' => $this->status,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
