<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'chair' => $this->chair,
            'accreditationStatus' => $this->accreditation_status,
            'complianceScore' => $this->compliance_score,
            'collegeId' => $this->college_id,
            'college' => $this->whenLoaded('college', fn () => new CollegeResource($this->college)),
        ];
    }
}
