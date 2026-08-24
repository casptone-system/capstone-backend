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

        $roleSlug = null;
        if ($primaryRole) {
            $roleSlug = strtolower(str_replace([' ', '_'], '-', $primaryRole));
            $roleSlug = preg_replace('/[^a-z0-9\-]/', '', $roleSlug);
        }

        $college = $this->college;
        $program = $this->program;
        $team = $this->team;

        // Compute department from available data
        $department = null;
        if ($college) {
            $department = $college->name;
        } elseif ($program) {
            $department = $program->name;
        } elseif ($team) {
            $department = $team->name;
        }

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
            'profilePhoto' => $this->profile_photo ? $this->profile_photo_url : null,
            'profilePhotoPath' => $this->profile_photo,
            'role' => $primaryRole ?: null,
            'role_slug' => $roleSlug,
            'roles' => $roles->values(),
            'permissions' => $this->getPermissionNames(),
            'department' => $department,
            'college_id' => $this->college_id ?? null,
            'collegeId' => $this->college_id ?? null,
            'college' => $college ? [
                'id' => $college->id,
                'name' => $college->name,
                'code' => $college->code,
            ] : null,
            'teamId' => $this->team_id ?? null,
            'team_id' => $this->team_id ?? null,
            'programId' => $this->getEffectiveProgramId(),
            'program_id' => $this->getEffectiveProgramId(),
        ];
    }
}
