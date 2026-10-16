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
        $trashed = method_exists($this->resource, 'trashed') && $this->resource->trashed();

        return [
            'id' => $this->id,
            'name' => trim(sprintf('%s %s %s', $this->first_name, $this->middle_name ?? '', $this->last_name)),
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'emailVerifiedAt' => $this->email_verified_at?->toDateTimeString(),
            'mustVerifyEmail' => (bool) config('adams.email_verification_enabled') && ! $this->hasVerifiedEmail(),
            'phone' => $this->phone_number,
            'birthdate' => $this->birth_date,
            'sex' => $this->sex,
            'profilePhoto' => ($this->profile_photo || $this->profile_photo_blob) ? $this->profile_photo_url : null,
            'profilePhotoPath' => $this->profile_photo,
            'role' => $primaryRole,
            'role_slug' => $primaryRole,
            'roles' => $roles,
            'permissions' => $this->getPermissionNames(),
            'department' => $department,
            'status' => $trashed ? 'Inactive' : 'Active',
            'deletedAt' => $this->deleted_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
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
            'last_login_at' => $this->resolvedLastLogin(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    private function resolvedLastLogin(): ?string
    {
        $lastLogin = $this->last_login_at ?: ($this->last_successful_login_at ?? null);

        if ($lastLogin instanceof \DateTimeInterface) {
            return $lastLogin->format('Y-m-d H:i:s');
        }

        return $lastLogin ? (string) $lastLogin : null;
    }
}
