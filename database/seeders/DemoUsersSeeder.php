<?php

namespace Database\Seeders;

use App\Models\AccreditationArea;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\User;
use App\Support\RoleSlug;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DemoUsersSeeder extends Seeder
{
    public const PASSWORD = 'AdamsDemo2026!';

    /**
     * @var list<array{role: string, email: string, first: string, last: string}>
     */
    public const ACCOUNTS = [
        ['role' => RoleSlug::SUPERADMIN, 'email' => 'superadmin@isu.edu.ph', 'first' => 'Sofia', 'last' => 'Reyes'],
        ['role' => RoleSlug::VPAA, 'email' => 'vpaa@isu.edu.ph', 'first' => 'Antonio', 'last' => 'Cruz'],
        ['role' => RoleSlug::QA, 'email' => 'qa@isu.edu.ph', 'first' => 'Liza', 'last' => 'Domingo'],
        ['role' => RoleSlug::DEAN, 'email' => 'dean@isu.edu.ph', 'first' => 'Maria', 'last' => 'Santos'],
        ['role' => RoleSlug::PROGRAM_CHAIR, 'email' => 'chair@isu.edu.ph', 'first' => 'Paolo', 'last' => 'Villanueva'],
        ['role' => RoleSlug::AREA_IN_CHARGE, 'email' => 'area@isu.edu.ph', 'first' => 'Kara', 'last' => 'Bautista'],
        ['role' => RoleSlug::FACULTY, 'email' => 'faculty@isu.edu.ph', 'first' => 'Miguel', 'last' => 'Torres'],
        ['role' => RoleSlug::ACCREDITOR, 'email' => 'accreditor@isu.edu.ph', 'first' => 'Elena', 'last' => 'Ramos'],
    ];

    public function run(): void
    {
        $college = College::query()->where('code', OrgStructureSeeder::IOF_CODE)->first();
        $program = Program::query()->where('code', OrgStructureSeeder::BSFAS_CODE)->first();

        $users = [];

        foreach (self::ACCOUNTS as $account) {
            Role::firstOrCreate(['name' => $account['role'], 'guard_name' => 'web']);

            $scopedToCollege = in_array($account['role'], [RoleSlug::DEAN], true);
            $scopedToProgram = in_array($account['role'], [
                RoleSlug::PROGRAM_CHAIR,
                RoleSlug::AREA_IN_CHARGE,
                RoleSlug::FACULTY,
            ], true);

            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['first'].' '.$account['last'],
                    'first_name' => $account['first'],
                    'middle_name' => null,
                    'last_name' => $account['last'],
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'college_id' => $scopedToCollege || $scopedToProgram ? $college?->id : null,
                    'program_id' => $scopedToProgram ? $program?->id : null,
                ]
            );

            $user->syncRoles([$account['role']]);
            $users[$account['role']] = $user;
            $this->command?->info("Demo user ready: {$account['email']} ({$account['role']})");
        }

        if ($program && isset($users[RoleSlug::PROGRAM_CHAIR])) {
            $chair = $users[RoleSlug::PROGRAM_CHAIR];
            $program->update([
                'chair_id' => $chair->id,
                'chair' => $chair->name,
            ]);

            $this->attachProgramMember($program, $chair, RoleSlug::PROGRAM_CHAIR);
        }

        if ($program && isset($users[RoleSlug::AREA_IN_CHARGE])) {
            $this->attachProgramMember($program, $users[RoleSlug::AREA_IN_CHARGE], RoleSlug::AREA_IN_CHARGE);
        }

        if ($program && isset($users[RoleSlug::FACULTY])) {
            $this->attachProgramMember($program, $users[RoleSlug::FACULTY], RoleSlug::FACULTY);
        }

        $area = $this->demoArea($program);
        if ($area && isset($users[RoleSlug::AREA_IN_CHARGE])) {
            $area->update(['chair_id' => $users[RoleSlug::AREA_IN_CHARGE]->id]);
        }

        if ($area && isset($users[RoleSlug::FACULTY])) {
            AreaMember::query()->updateOrCreate(
                [
                    'area_id' => $area->id,
                    'user_id' => $users[RoleSlug::FACULTY]->id,
                ],
                ['role' => 'member']
            );
        }
    }

    private function attachProgramMember(Program $program, User $user, string $role): void
    {
        ProgramMember::query()->updateOrCreate(
            [
                'program_id' => $program->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'joined_at' => now(),
            ]
        );
    }

    private function demoArea(?Program $program): ?AccreditationArea
    {
        if (! $program) {
            return null;
        }

        $cycleId = $program->active_cycle_id
            ?: $program->accreditationCycles()->orderBy('id')->value('id');

        if (! $cycleId) {
            return null;
        }

        return AccreditationArea::query()
            ->where('cycle_id', $cycleId)
            ->orderBy('id')
            ->first();
    }
}
