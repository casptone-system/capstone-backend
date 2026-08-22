<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FacultyMyAreasTest extends TestCase
{
    use RefreshDatabase;

    private User $faculty;
    private User $otherFaculty;
    private User $qa;
    private AccreditationArea $area1;
    private AccreditationArea $area3;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'QA', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'VPAA', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create(['program_id' => $program->id]);

        $this->faculty = User::factory()->create(['program_id' => $program->id]);
        $this->faculty->assignRole('Faculty');

        $this->otherFaculty = User::factory()->create(['program_id' => $program->id]);
        $this->otherFaculty->assignRole('Faculty');

        $this->qa = User::factory()->create();
        $this->qa->assignRole('QA');

        $this->area1 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'name' => 'Area 1 – Vision, Mission, Goals and Objectives',
            'status' => 'Not Started',
            'chair_id' => $this->faculty->id,
        ]);

        $this->area3 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-3',
            'name' => 'Area 3 – Curriculum and Instruction',
            'status' => 'Not Started',
        ]);

        AreaMember::create([
            'area_id' => $this->area3->id,
            'user_id' => $this->faculty->id,
            'role' => 'member',
        ]);

        AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-2',
            'name' => 'Area 2 – Faculty',
            'status' => 'Not Started',
        ]);
    }

    public function test_my_areas_returns_only_assigned_chair_and_member_areas(): void
    {
        Sanctum::actingAs($this->faculty);

        $response = $this->getJson('/api/users/me/areas');

        $response->assertStatus(200)->assertJsonPath('success', true);

        $areas = $response->json('data');
        $codes = collect($areas)->pluck('code')->all();

        $this->assertEquals(['area-1', 'area-3'], $codes);
        $this->assertEquals('AREA 1', $areas[0]['label']);
        $this->assertEquals('chair', $areas[0]['assignmentRole']);
        $this->assertEquals('AREA 3', $areas[1]['label']);
        $this->assertEquals('member', $areas[1]['assignmentRole']);
    }

    public function test_my_areas_returns_empty_list_when_user_has_no_assignments(): void
    {
        Sanctum::actingAs($this->otherFaculty);

        $response = $this->getJson('/api/users/me/areas');

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertSame([], $response->json('data'));
    }

    public function test_unassigned_faculty_cannot_view_area_parameters(): void
    {
        Sanctum::actingAs($this->otherFaculty);

        $this->getJson("/api/accreditation-areas/{$this->area1->id}/parameters")
            ->assertStatus(403);
    }

    public function test_assigned_faculty_sees_seeded_area_1_parameters_and_rows(): void
    {
        Sanctum::actingAs($this->faculty);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area1->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(2, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('STATEMENT OF VISION, MISSION, GOALS AND OBJECTIVES', $parameters[0]['name']);
        $this->assertEquals('B', $parameters[1]['code']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(18, $rows);
        $this->assertFalse($rows[0]['isDone']);
        $this->assertSame('SYSTEM - INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('0.1. The VMGO are crafted and duly approved by the BOR/BOT.', $rows[17]['content']);
    }

    public function test_mark_as_done_is_shared_across_area_team(): void
    {
        AreaMember::create([
            'area_id' => $this->area1->id,
            'user_id' => $this->otherFaculty->id,
            'role' => 'member',
        ]);

        Sanctum::actingAs($this->faculty);
        $parameter = $this->getJson("/api/accreditation-areas/{$this->area1->id}/parameters")->json('data.0');
        $row = $this->getJson("/api/parameters/{$parameter['id']}/rows")->json('data.0');

        $this->patchJson("/api/parameter-rows/{$row['id']}/status", ['is_done' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.isDone', true);

        Sanctum::actingAs($this->otherFaculty);
        $again = $this->getJson("/api/parameters/{$parameter['id']}/rows")->json('data.0');

        $this->assertTrue($again['isDone']);
        $this->assertEquals($this->faculty->id, $again['doneBy']['id']);
    }

    public function test_unassigned_faculty_cannot_toggle_status(): void
    {
        Sanctum::actingAs($this->faculty);
        $parameter = $this->getJson("/api/accreditation-areas/{$this->area1->id}/parameters")->json('data.0');
        $row = $this->getJson("/api/parameters/{$parameter['id']}/rows")->json('data.0');

        Sanctum::actingAs($this->otherFaculty);
        $this->patchJson("/api/parameter-rows/{$row['id']}/status", ['is_done' => true])
            ->assertStatus(403);
    }

    public function test_faculty_cannot_edit_row_content(): void
    {
        Sanctum::actingAs($this->faculty);
        $parameter = $this->getJson("/api/accreditation-areas/{$this->area1->id}/parameters")->json('data.0');
        $row = $this->getJson("/api/parameters/{$parameter['id']}/rows")->json('data.0');

        $this->patchJson("/api/parameter-rows/{$row['id']}/content", [
            'content' => 'Faculty should not be able to change this',
        ])->assertStatus(403);

        $this->assertDatabaseHas('parameter_content_rows', [
            'id' => $row['id'],
            'content' => $row['content'],
        ]);
    }

    public function test_qa_can_edit_row_content(): void
    {
        Sanctum::actingAs($this->faculty);
        $parameter = $this->getJson("/api/accreditation-areas/{$this->area1->id}/parameters")->json('data.0');
        $row = $this->getJson("/api/parameters/{$parameter['id']}/rows")->json('data.0');

        Sanctum::actingAs($this->qa);
        $this->patchJson("/api/parameter-rows/{$row['id']}/content", [
            'content' => 'Updated VMGO statement for QA review',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.content', 'Updated VMGO statement for QA review');

        $this->assertDatabaseHas('parameter_content_rows', [
            'id' => $row['id'],
            'content' => 'Updated VMGO statement for QA review',
            'updated_by' => $this->qa->id,
        ]);
    }

    public function test_qa_can_list_areas_and_add_a_content_row(): void
    {
        Sanctum::actingAs($this->qa);

        $areas = $this->getJson('/api/qa/areas')->assertStatus(200)->json('data');
        $this->assertNotEmpty($areas);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area3->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertNotEmpty($parameters);

        $this->postJson("/api/parameters/{$parameters[0]['id']}/rows", [
            'content' => 'QA-authored curriculum indicator',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.content', 'QA-authored curriculum indicator');

        $this->assertDatabaseHas('parameter_content_rows', [
            'parameter_id' => $parameters[0]['id'],
            'content' => 'QA-authored curriculum indicator',
        ]);
    }

    public function test_faculty_cannot_create_parameters_or_rows(): void
    {
        Sanctum::actingAs($this->faculty);

        $this->postJson("/api/accreditation-areas/{$this->area1->id}/parameters", [
            'code' => 'C',
            'name' => 'Should not be created',
        ])->assertStatus(403);

        $parameter = AccreditationParameter::create([
            'area_id' => $this->area1->id,
            'code' => 'Z',
            'name' => 'Hidden',
            'sort_order' => 9,
        ]);

        $this->postJson("/api/parameters/{$parameter->id}/rows", [
            'content' => 'Faculty authored',
        ])->assertStatus(403);

        $this->assertSame(0, ParameterContentRow::where('parameter_id', $parameter->id)->count());
    }
}
