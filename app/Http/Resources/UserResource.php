<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roles = collect($this->getRoleNames());
        $primaryRole = $roles->first() ?: null;

        return [
            'id' => $this->id,
            'name' => trim(sprintf('%s %s %s', $this->first_name, $this->middle_name ?? '', $this->last_name)),
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'emailVerifiedAt' => $this->email_verified_at?->toDateTimeString(),
            'phone' => $this->phone_number,
            'birthdate' => $this->birth_date,
            'profilePhoto' => $this->profile_photo,
            'role' => $primaryRole ? strtolower(str_replace(' ', '-', $primaryRole)) : null,
            'roles' => $roles->values(),
            'permissions' => $this->getPermissionNames(),
            'teamId' => $this->team_id ?? null,
            'programId' => $this->program_id ?? null,
        ];
    }
}
