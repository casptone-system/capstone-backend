<?php

namespace App\Http\Resources;

use App\Support\RoleSlug;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roles = collect($this->getRoleNames())
            ->map(fn (string $name) => RoleSlug::canonicalize($name) ?? $name)
            ->filter()
            ->unique()
            ->values();
        $primaryRole = $roles->first() ?: null;

        $college = $this->college;
        $program = $this->program;
        $team = $this->team;

        // `department` is a UI label only (there is no Department entity).
        // It is the college name when college_id is set, otherwise the program name.
        // It is no longer inferred from team/program_members fallbacks.
        $department = $college?->name ?? $program?->name;

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
            'role' => $primaryRole,
            'role_slug' => $primaryRole,
            'roles' => $roles,
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
            'programId' => $this->program_id,
            'program_id' => $this->program_id,
            'chaired_program_id' => $this->chairedProgramId(),
            'program' => $program ? [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->code,
            ] : null,
        ];
    }
}
