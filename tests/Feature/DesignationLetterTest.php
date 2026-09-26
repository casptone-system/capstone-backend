<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\DesignationFile;
use App\Models\Program;
use App\Models\User;
use App\Services\DesignationLetterService;
use App\Services\EvidenceStorage;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DesignationLetterTest extends TestCase
{
    use RefreshDatabase;

    public function test_letter_uses_the_validity_date_the_vpaa_saved(): void
    {
        Carbon::setTestNow('2026-09-23 08:00:00');

        $college = College::factory()->create(['name' => 'Institute of Fisheries']);
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'Bachelor of Science in Fisheries and Aquatic Sciences',
        ]);

        $activeCycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'level' => 'Level I',
            'valid_until' => '2027-06-30',
        ]);
        $program->update(['active_cycle_id' => $activeCycle->id]);

        $assignedCycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'level' => 'Level II',
            'valid_until' => null,
        ]);
        $area = AccreditationArea::factory()->create([
            'cycle_id' => $assignedCycle->id,
            'code' => 'area-1',
            'name' => 'Area 1 - Vision, Mission, Goals and Objectives',
        ]);

        Role::findOrCreate(RoleSlug::FACULTY, 'web');
        $faculty = User::factory()->create([
            'first_name' => 'Miguel',
            'middle_name' => null,
            'last_name' => 'Torres',
            'email' => 'faculty@gmail.com',
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $faculty->assignRole(RoleSlug::FACULTY);

        Role::findOrCreate(RoleSlug::PROGRAM_CHAIR, 'web');
        $chair = User::factory()->create([
            'first_name' => 'Paolo',
            'middle_name' => null,
            'last_name' => 'Villanueva',
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $chair->assignRole(RoleSlug::PROGRAM_CHAIR);

        $file = app(DesignationLetterService::class)->issue(
            $faculty,
            $area,
            DesignationFile::ROLE_MEMBER,
            $chair,
        );

        $this->assertSame('2027-06-30', $file->valid_until?->toDateString());

        $pdf = app(EvidenceStorage::class)->getContents($file->file_path);
        $this->assertNotNull($pdf);
        $this->assertStringContainsString('September 23, 2026 to June 30, 2027', $pdf);
        $this->assertStringNotContainsString('until the validity date set by the VPAA', $pdf);
    }
}
