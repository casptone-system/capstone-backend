<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_email' => $this->user_email,
            'event' => $this->event,
            'method' => $this->method,
            'path' => $this->path,
            'status' => $this->status,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'details' => [
                'user_agent' => $this->details?->user_agent,
                'exception' => $this->details?->exception,
            ],
        ];
    }
}
