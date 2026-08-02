<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => trim(sprintf('%s %s %s', $this->first_name, $this->middle_name ?? '', $this->last_name)),
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getPermissionNames(),
        ];
    }
}
