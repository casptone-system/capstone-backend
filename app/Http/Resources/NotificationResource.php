<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'type' => $data['type'] ?? Str::snake(class_basename($this->type), '_'),
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? null,
            'data' => $data,
            'readAt' => $this->read_at?->toDateTimeString(),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'isRead' => $this->read_at !== null,
        ];
    }
}