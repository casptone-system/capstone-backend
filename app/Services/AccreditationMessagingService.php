<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\Program;
use App\Models\User;

class AccreditationMessagingService
{
    public const TYPES = [
        'vpaa_dean', 'dean_vpaa',
        'dean_chair', 'chair_dean',
        'chair_area', 'area_chair',
        'chair_faculty', 'faculty_chair',
        'area_faculty', 'faculty_area',
        'qa_vpaa', 'vpaa_qa',
        'qa_dean', 'dean_qa',
        'qa_chair', 'chair_qa',
    ];

    public function allowedTypesFor(User $user): array
    {
        return match (true) {
            $user->isVPAA() => [
                ['value' => 'vpaa_dean', 'label' => 'Dean'],
                ['value' => 'vpaa_qa', 'label' => 'QA'],
            ],
            $user->isDean() => [
                ['value' => 'dean_vpaa', 'label' => 'VPAA / DI'],
                ['value' => 'dean_chair', 'label' => 'Program Chair'],
                ['value' => 'dean_qa', 'label' => 'QA'],
            ],
            $user->isProgramChair() => [
                ['value' => 'chair_dean', 'label' => 'Dean'],
                ['value' => 'chair_area', 'label' => 'Area Chair'],
                ['value' => 'chair_faculty', 'label' => 'Faculty'],
                ['value' => 'chair_qa', 'label' => 'QA'],
            ],
            $user->isAreaIncharge() => [
                ['value' => 'area_chair', 'label' => 'Program Chair'],
                ['value' => 'area_faculty', 'label' => 'Faculty'],
            ],
            $user->isFaculty() => [
                ['value' => 'faculty_chair', 'label' => 'Program Chair'],
                ['value' => 'faculty_area', 'label' => 'Area Chair'],
            ],
            $user->isQA() => [
                ['value' => 'qa_vpaa', 'label' => 'VPAA / DI'],
                ['value' => 'qa_dean', 'label' => 'Dean'],
                ['value' => 'qa_chair', 'label' => 'Program Chair'],
            ],
            default => [],
        };
    }

    public function contacts(User $user): array
    {
        $groups = [];

        foreach ($this->allowedTypesFor($user) as $type) {
            $people = $this->recipientsForType($user, $type['value']);
            if ($people->isEmpty()) {
                continue;
            }
            $groups[] = [
                'type' => $type['value'],
                'label' => $type['label'],
                'users' => $people->values(),
            ];
        }

        return [
            'types' => $this->allowedTypesFor($user),
            'groups' => $groups,
        ];
    }

    public function assertCanCreate(User $sender, string $type, array $participantIds): void
    {
        if (! in_array($type, self::TYPES, true)) {
            abort(403, 'That conversation type is not allowed.');
        }

        $allowed = collect($this->allowedTypesFor($sender))->pluck('value');
        if (! $allowed->contains($type)) {
            abort(403, 'You are not allowed to start this kind of conversation.');
        }

        $allowedIds = $this->recipientsForType($sender, $type)->pluck('id')->all();
        foreach ($participantIds as $participantId) {
            if ((int) $participantId === (int) $sender->id) {
                abort(422, 'You cannot message yourself.');
            }
            if (! in_array((int) $participantId, array_map('intval', $allowedIds), true)) {
                abort(403, 'That recipient is outside your accreditation communication chain.');
            }
        }
    }

    private function recipientsForType(User $user, string $type)
    {
        $query = User::query()->with('roles')->where('id', '!=', $user->id);

        return match ($type) {
            'vpaa_dean', 'qa_dean' => $query->role('Dean')->orderBy('name')->get()->map(fn ($person) => $this->mapUser($person)),
            'dean_vpaa', 'qa_vpaa' => $this->usersWithRole($query, ['VPAA']),
            'vpaa_qa', 'dean_qa', 'chair_qa' => $this->usersWithRole($query, ['QA']),
            'dean_chair', 'qa_chair' => $this->programChairsFor($user),
            'chair_dean' => $this->deanForChair($user),
            'chair_area' => $this->areaChairsForProgramChair($user),
            'chair_faculty', 'area_faculty' => $this->facultyForSender($user),
            'area_chair', 'faculty_chair' => $this->programChairFor($user),
            'faculty_area' => $this->areaChairsForFaculty($user),
            default => collect(),
        };
    }

    private function usersWithRole($query, array $roles)
    {
        return $query->role($roles)->orderBy('name')->get()->map(fn ($person) => $this->mapUser($person));
    }

    private function programChairsFor(User $user)
    {
        $query = User::role('Program Chair')->where('id', '!=', $user->id)->orderBy('name');
        if ($user->isDean() && $user->getEffectiveCollegeId()) {
            $programIds = Program::where('college_id', $user->getEffectiveCollegeId())->pluck('id');
            $query->where(function ($inner) use ($programIds, $user) {
                $inner->whereIn('program_id', $programIds)
                    ->orWhere('college_id', $user->getEffectiveCollegeId());
            });
        }

        return $query->get()->map(fn ($person) => $this->mapUser($person));
    }

    private function deanForChair(User $user)
    {
        $collegeId = $user->getEffectiveCollegeId();
        if (! $collegeId) {
            return collect();
        }

        return User::role('Dean')->where('college_id', $collegeId)->orderBy('name')->get()->map(fn ($person) => $this->mapUser($person));
    }

    private function programChairFor(User $user)
    {
        $programId = $user->getEffectiveProgramId() ?: $user->assignedProgramId();
        if (! $programId) {
            return collect();
        }
        $program = Program::find($programId);
        if (! $program?->chair_id) {
            return collect();
        }

        return User::where('id', $program->chair_id)->get()->map(fn ($person) => $this->mapUser($person));
    }

    private function areaChairsForProgramChair(User $user)
    {
        $programId = $user->assignedProgramId();
        if (! $programId) {
            return collect();
        }

        $chairIds = AccreditationArea::whereHas('cycle', fn ($cycle) => $cycle->where('program_id', $programId))
            ->whereNotNull('chair_id')
            ->pluck('chair_id');

        return User::whereIn('id', $chairIds)->orderBy('name')->get()->map(function ($person) {
            $mapped = $this->mapUser($person);
            $mapped['label'] = ($person->name).' · Area Chair';
            return $mapped;
        });
    }

    private function areaChairsForFaculty(User $user)
    {
        $areaChairIds = AccreditationArea::query()
            ->where(function ($query) use ($user) {
                $query->where('chair_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
            })
            ->whereNotNull('chair_id')
            ->pluck('chair_id');

        return User::whereIn('id', $areaChairIds)->where('id', '!=', $user->id)->orderBy('name')->get()->map(fn ($person) => $this->mapUser($person));
    }

    private function facultyForSender(User $user)
    {
        $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();
        if (! $programId) {
            return collect();
        }

        return User::query()
            ->where('id', '!=', $user->id)
            ->where('program_id', $programId)
            ->whereHas('roles', fn ($roles) => $roles->whereRaw('LOWER(name) = ?', ['faculty']))
            ->orderBy('name')
            ->get()
            ->map(fn ($person) => $this->mapUser($person));
    }

    private function mapUser(User $person): array
    {
        $role = $person->roles->pluck('name')->first() ?: 'User';
        if ($person->isAreaIncharge()) {
            $role = 'Area Chair';
        }

        return [
            'id' => $person->id,
            'name' => $person->name,
            'email' => $person->email,
            'role' => $role,
            'label' => $person->name.' · '.$role,
            'photo' => $person->profile_photo_url,
        ];
    }
}
