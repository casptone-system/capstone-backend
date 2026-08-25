<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\College;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\User;
use App\Support\RoleSlug;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaMonitoringTest extends TestCase
{
    private User $qa;

    private College $collegeA;

    private College $collegeB;

    private Program $programA;

    private Program $programB;

    private AccreditationCycle $cycleA;

    private AccreditationCycle $cycleB;

    private AccreditationArea $areaA;

    private ParameterContentRow $row;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collegeA = College::factory()->create(['name' => 'College of Computing']);
        $this->collegeB = College::factory()->create(['name' => 'College of Business']);
        $this->programA = Program::factory()->create([
            'college_id' => $this->collegeA->id,
            'name' => 'BSIT',
            'code' => 'BSIT',
        ]);
        $this->programB = Program::factory()->create([
            'college_id' => $this->collegeB->id,
            'name' => 'BSBA',
            'code' => 'BSBA',
        ]);
        $this->cycleA = AccreditationCycle::factory()->create([
            'program_id' => $this->programA->id,
            'college_id' => $this->collegeA->id,
            'status' => 'Preparation',
        ]);
        $this->cycleB = AccreditationCycle::factory()->create([
            'program_id' => $this->programB->id,
            'college_id' => $this->collegeB->id,
            'status' => 'Preparation',
        ]);
        $this->areaA = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycleA->id,
            'code' => 'area-1',
            'name' => 'Vision, Mission, Goals and Objectives',
        ]);
        AccreditationArea::factory()->create([
            'cycle_id' => $this->cycleB->id,
            'code' => 'area-1',
            'name' => 'Vision, Mission, Goals and Objectives',
        ]);

        $parameter = AccreditationParameter::create([
            'area_id' => $this->areaA->id,
            'code' => 'A',
            'name' => 'Parameter A',
            'sort_order' => 1,
        ]);
        $this->row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'Original statement',
            'sort_order' => 1,
        ]);

        $this->qa = User::factory()->create(['college_id' => null, 'program_id' => null]);
        $this->qa->assignRole(RoleSlug::QA);
    }

    public function test_qa_monitoring_endpoints_cover_every_college_and_program(): void
    {
        Sanctum::actingAs($this->qa);

        $dashboard = $this->getJson('/api/qa/dashboard')->assertOk()->json('data');
        $programNames = collect($dashboard['programs'])->pluck('program_name');
        $this->assertTrue($programNames->contains('BSIT'));
        $this->assertTrue($programNames->contains('BSBA'));

        $readiness = $this->getJson('/api/qa/reports/program-readiness')->assertOk()->json('data.programs');
        $this->assertTrue(collect($readiness)->pluck('program_name')->contains('BSIT'));
        $this->assertTrue(collect($readiness)->pluck('program_name')->contains('BSBA'));

        $colleges = $this->getJson('/api/qa/reports/college-comparison')->assertOk()->json('data.colleges');
        $this->assertTrue(collect($colleges)->pluck('college_name')->contains('College of Computing'));
        $this->assertTrue(collect($colleges)->pluck('college_name')->contains('College of Business'));

        $this->getJson('/api/qa/reports/at-risk-programs')->assertOk();

        $cycles = $this->getJson('/api/qa/accreditations')->assertOk()->json('data.data');
        $programIds = collect($cycles)->pluck('program_id');
        $this->assertTrue($programIds->contains($this->programA->id));
        $this->assertTrue($programIds->contains($this->programB->id));

        $this->getJson('/api/qa/accreditations/'.$this->cycleB->id)->assertOk()
            ->assertJsonPath('data.program.id', $this->programB->id);

        $areas = $this->getJson('/api/qa/areas')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(2, count($areas));

        $listedColleges = $this->getJson('/api/colleges')->assertOk()->json();
        $collegeRows = $listedColleges['data']['data'] ?? $listedColleges['data'];
        $this->assertGreaterThanOrEqual(2, count($collegeRows));
    }

    public function test_non_qa_roles_cannot_access_qa_endpoints(): void
    {
        $dean = User::factory()->create(['college_id' => $this->collegeA->id]);
        $dean->assignRole(RoleSlug::DEAN);
        Sanctum::actingAs($dean);
        $this->getJson('/api/qa/dashboard')->assertForbidden();
        $this->getJson('/api/qa/reports/program-readiness')->assertForbidden();
        $this->getJson('/api/colleges')->assertForbidden();

        $faculty = User::factory()->create(['program_id' => $this->programA->id]);
        $faculty->assignRole(RoleSlug::FACULTY);
        Sanctum::actingAs($faculty);
        $this->getJson('/api/qa/dashboard')->assertForbidden();
        $this->getJson('/api/qa/areas')->assertForbidden();
    }

    public function test_qa_cannot_mutate_instruments_content_cycles_or_areas(): void
    {
        Sanctum::actingAs($this->qa);

        $this->postJson('/api/instrument-templates', [
            'name' => 'Level I Instrument',
            'level' => 'Level I',
            'areas' => [['name' => 'Area 1']],
        ])->assertForbidden();

        $this->patchJson('/api/parameter-rows/'.$this->row->id.'/content', [
            'content' => 'QA rewrite',
        ])->assertForbidden();

        $this->postJson('/api/parameters/'.$this->row->parameter_id.'/rows', [
            'content' => 'QA added row',
        ])->assertForbidden();

        $this->deleteJson('/api/parameter-rows/'.$this->row->id)->assertForbidden();

        $this->putJson('/api/accreditation-cycles/'.$this->cycleA->id, [
            'status' => 'Ready',
        ])->assertForbidden();

        $this->deleteJson('/api/accreditation-cycles/'.$this->cycleA->id)->assertForbidden();

        $this->postJson('/api/accreditation-areas', [
            'cycle_id' => $this->cycleA->id,
            'name' => 'Extra area',
        ])->assertForbidden();

        $this->postJson('/api/accreditation-cycles', [
            'college_id' => $this->collegeA->id,
            'program_id' => $this->programA->id,
            'status' => 'Preparation',
        ])->assertForbidden();
    }
}
