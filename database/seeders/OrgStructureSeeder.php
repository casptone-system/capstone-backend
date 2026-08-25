<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Services\AaccupStructureService;
use App\Support\RoleSlug;
use Illuminate\Database\Seeder;

class OrgStructureSeeder extends Seeder
{
    public const IOF_CODE = 'IOF';

    public const BSFAS_CODE = 'BSFAS';

    /**
     * Seed the current respondent unit without blocking future colleges/programs.
     */
    public function run(): void
    {
        $this->seedIof();
    }

    public function seedIof(): Program
    {
        $college = College::query()->updateOrCreate(
            ['code' => self::IOF_CODE],
            [
                'name' => 'Institute of Fisheries',
                'campus' => config('institution.campus'),
                'description' => 'Institute of Fisheries, '.config('institution.name').' — '.config('institution.campus').'. Current respondent unit for ADAMS. Additional colleges and programs can be added later.',
            ]
        );

        $program = Program::query()->updateOrCreate(
            ['code' => self::BSFAS_CODE],
            [
                'college_id' => $college->id,
                'name' => 'Bachelor of Science in Fisheries and Aquatic Sciences',
                'accreditation_status' => 'compliant',
            ]
        );

        app(AaccupStructureService::class)->bootstrapProgram($program);

        return $program->fresh();
    }

    /**
     * Keep IOF/BSFAS and remove other colleges/programs from this environment.
     * Institution-wide users stay unscoped; college/program users are moved onto IOF.
     */
    public function pruneOtherUnits(): int
    {
        $program = $this->seedIof();
        $college = $program->college()->firstOrFail();

        User::query()
            ->whereNotNull('college_id')
            ->where('college_id', '!=', $college->id)
            ->update(['college_id' => $college->id]);

        User::query()
            ->whereNotNull('program_id')
            ->where('program_id', '!=', $program->id)
            ->update([
                'program_id' => $program->id,
                'college_id' => $college->id,
            ]);

        $removed = College::query()->where('code', '!=', self::IOF_CODE)->count();
        College::query()->where('code', '!=', self::IOF_CODE)->get()->each->delete();

        Program::query()
            ->where('college_id', $college->id)
            ->where('code', '!=', self::BSFAS_CODE)
            ->get()
            ->each->delete();

        $program->refresh();
        if (! $program->chair_id) {
            $chair = User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', RoleSlug::PROGRAM_CHAIR))
                ->where('program_id', $program->id)
                ->orderByDesc('id')
                ->first();

            if ($chair) {
                $program->update([
                    'chair_id' => $chair->id,
                    'chair' => $chair->name,
                ]);
            }
        }

        app(AaccupStructureService::class)->bootstrapProgram($program->fresh());

        return $removed;
    }
}
