<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollegeResource extends JsonResource
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
            'campus' => $this->campus,
            'description' => $this->description,
            'programs' => $this->whenLoaded('programs', fn () => ProgramResource::collection($this->programs)),
        ];
    }
}
