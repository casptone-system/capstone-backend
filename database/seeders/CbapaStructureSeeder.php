<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\User;
use App\Services\AaccupStructureService;
use App\Support\RoleSlug;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class CbapaStructureSeeder extends Seeder
{
    public const COLLEGE_CODE = 'CBAPA';

    public const PROGRAM_CODE = 'BSPA';

    public const CHAIR_EMAIL = 'chair.bspa@isu.edu.ph';

    public function run(AaccupStructureService $structure): void
    {
        $college = College::query()->updateOrCreate(
            ['code' => self::COLLEGE_CODE],
            [
                'name' => 'College of Business, Accountancy, and Public Administration',
                'campus' => config('institution.campus'),
                'description' => 'College of Business, Accountancy, and Public Administration, '.config('institution.name').' — '.config('institution.campus').'.',
            ]
        );

        $program = Program::query()->updateOrCreate(
            ['code' => self::PROGRAM_CODE],
            [
                'college_id' => $college->id,
                'name' => 'Bachelor of Science in Public Administration',
                'accreditation_status' => 'compliant',
            ]
        );

        $chair = $this->seedChair($college, $program);
        $program->update([
            'chair_id' => $chair->id,
            'chair' => $chair->name,
        ]);

        ProgramMember::query()->updateOrCreate(
            [
                'program_id' => $program->id,
                'user_id' => $chair->id,
            ],
            [
                'role' => RoleSlug::PROGRAM_CHAIR,
                'joined_at' => now(),
            ]
        );

        $structure->bootstrapProgram($program->fresh());

        $this->command?->info("CBAPA / BSPA ready. Program chair: {$chair->email}");
    }

    private function seedChair(College $college, Program $program): User
    {
        Role::firstOrCreate(['name' => RoleSlug::PROGRAM_CHAIR, 'guard_name' => 'web']);

        $chair = User::query()->updateOrCreate(
            ['email' => self::CHAIR_EMAIL],
            [
                'name' => 'Teresa Aguilar',
                'first_name' => 'Teresa',
                'middle_name' => null,
                'last_name' => 'Aguilar',
                'password' => DemoUsersSeeder::PASSWORD,
                'college_id' => $college->id,
                'program_id' => $program->id,
            ]
        );

        $chair->forceFill(['email_verified_at' => $chair->email_verified_at ?? now()])->save();
        $chair->syncRoles([RoleSlug::PROGRAM_CHAIR]);

        return $chair;
    }
}
