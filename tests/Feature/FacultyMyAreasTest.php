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
    private AccreditationArea $area2;
    private AccreditationArea $area3;
    private AccreditationArea $area4;
    private AccreditationArea $area5;
    private AccreditationArea $area6;
    private AccreditationArea $area7;
    private AccreditationArea $area8;
    private AccreditationArea $area9;
    private AccreditationArea $area10;

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

        $this->area2 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-2',
            'name' => 'Area 2 – Faculty',
            'status' => 'Not Started',
        ]);

        $this->area4 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-4',
            'name' => 'Area 4 – Support to Students',
            'status' => 'Not Started',
        ]);

        $this->area5 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-5',
            'name' => 'Area 5 – Research',
            'status' => 'Not Started',
        ]);

        $this->area6 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-6',
            'name' => 'Area 6 – Extension and Community Involvement',
            'status' => 'Not Started',
        ]);

        $this->area7 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-7',
            'name' => 'Area 7 – Library',
            'status' => 'Not Started',
        ]);

        $this->area8 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-8',
            'name' => 'Area 8 – Physical Plant and Facilities',
            'status' => 'Not Started',
        ]);

        $this->area9 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-9',
            'name' => 'Area 9 – Laboratories',
            'status' => 'Not Started',
        ]);

        $this->area10 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-10',
            'name' => 'Area 10 – Administration',
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
        $this->assertSame('O.1. The VMGO are crafted and duly approved by the BOR/BOT.', $rows[17]['content']);
    }

    public function test_area_2_seeds_eight_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area2->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(8, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('ACADEMIC QUALIFICATIONS AND PROFESSIONAL EXPERIENCE', $parameters[0]['name']);
        $this->assertEquals('RECRUITMENT, SELECTION AND ORIENTATION', $parameters[1]['name']);
        $this->assertEquals('H', $parameters[7]['code']);
        $this->assertEquals('PROFESSIONALISM', $parameters[7]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.1. The institution has qualified and competent faculty.', end($rows)['content']);
    }

    public function test_area_3_seeds_six_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area3->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(6, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('CURRICULUM AND PROGRAM OF STUDIES', $parameters[0]['name']);
        $this->assertEquals('INSTRUCTIONAL PROCESS, METHODOLOGIES AND LEARNING OPPORTUNITIES', $parameters[1]['name']);
        $this->assertEquals('F', $parameters[5]['code']);
        $this->assertEquals('ADMINISTRATIVE SUPPORT FOR EFFECTIVE INSTRUCTION', $parameters[5]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.1. The curriculum is responsive and relevant to the demand of times.', end($rows)['content']);
    }

    public function test_area_4_seeds_five_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area4->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(5, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('STUDENT SERVICES PROGRAM (SSP)', $parameters[0]['name']);
        $this->assertEquals('STUDENT WELFARE', $parameters[1]['name']);
        $this->assertEquals('STUDENT DEVELOPMENT', $parameters[2]['name']);
        $this->assertEquals('INSTITUTIONAL STUDENT PROGRAMS AND SERVICES', $parameters[3]['name']);
        $this->assertEquals('E', $parameters[4]['code']);
        $this->assertEquals('RESEARCH, MONITORING AND EVALUATION', $parameters[4]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('Objectives', $rows[1]['content']);
        $this->assertSame('O.1. The students are satisfied with the Student Services Program.', end($rows)['content']);

        $paramERows = $this->getJson("/api/parameters/{$parameters[4]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('O.1. Research outputs are presented and published.', end($paramERows)['content']);
    }

    public function test_area_5_seeds_four_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area5->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(4, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('PRIORITIES AND RELEVANCE', $parameters[0]['name']);
        $this->assertEquals('FUNDING AND OTHER RESOURCES', $parameters[1]['name']);
        $this->assertEquals('IMPLEMENTATION, MONITORING, EVALUATION AND UTILIZATION OF RESEARCH RESULTS/OUTPUTS', $parameters[2]['name']);
        $this->assertEquals('D', $parameters[3]['code']);
        $this->assertEquals('PUBLICATION AND DISSEMINATION', $parameters[3]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.2. Research results are published.', end($rows)['content']);

        $paramDRows = $this->getJson("/api/parameters/{$parameters[3]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('O.3. Patented and copyrighted research outputs are commercialized.', end($paramDRows)['content']);
    }

    public function test_area_6_seeds_four_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area6->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(4, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('PRIORITIES AND RELEVANCE', $parameters[0]['name']);
        $this->assertEquals('PLANNING, IMPLEMENTATION, MONITORING AND EVALUATION', $parameters[1]['name']);
        $this->assertEquals('FUNDING AND OTHER RESOURCES', $parameters[2]['name']);
        $this->assertEquals('D', $parameters[3]['code']);
        $this->assertEquals('COMMUNITY INVOLVEMENT AND PARTICIPATION IN THE INSTITUTION\'S ACTIVITIES', $parameters[3]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.1. Priority and relevant extension projects and activities are conducted.', end($rows)['content']);

        $paramDRows = $this->getJson("/api/parameters/{$parameters[3]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('O.1. There is wholesome coordination between the Extension Program implementers and the target clientele/beneficiaries.', end($paramDRows)['content']);
    }

    public function test_area_7_seeds_seven_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area7->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(7, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('ADMINISTRATION', $parameters[0]['name']);
        $this->assertEquals('ADMINISTRATIVE STAFF', $parameters[1]['name']);
        $this->assertEquals('COLLECTION DEVELOPMENT, ORGANIZATION AND PRESERVATION', $parameters[2]['name']);
        $this->assertEquals('SERVICES AND UTILIZATION', $parameters[3]['name']);
        $this->assertEquals('PHYSICAL SET-UP AND FACILITIES', $parameters[4]['name']);
        $this->assertEquals('FINANCIAL SUPPORT', $parameters[5]['name']);
        $this->assertEquals('G', $parameters[6]['code']);
        $this->assertEquals('LINKAGES', $parameters[6]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.2. The library organizational structure is well-designed and effectively implemented.', end($rows)['content']);

        $paramGRows = $this->getJson("/api/parameters/{$parameters[6]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('O.1. Library resource sharing and linkages are well-established.', end($paramGRows)['content']);
    }

    public function test_area_8_seeds_ten_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area8->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(10, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('CAMPUS', $parameters[0]['name']);
        $this->assertEquals('BUILDINGS', $parameters[1]['name']);
        $this->assertEquals('CLASSROOMS', $parameters[2]['name']);
        $this->assertEquals('OFFICES AND STAFF ROOMS', $parameters[3]['name']);
        $this->assertEquals('ASSEMBLY, ATHLETIC AND SPORTS FACILITIES', $parameters[4]['name']);
        $this->assertEquals('MEDICAL AND DENTAL CLINIC', $parameters[5]['name']);
        $this->assertEquals('STUDENT CENTER', $parameters[6]['name']);
        $this->assertEquals('FOOD SERVICES/CANTEEN/CAFETERIA', $parameters[7]['name']);
        $this->assertEquals('ACCREDITATION CENTER', $parameters[8]['name']);
        $this->assertEquals('J', $parameters[9]['code']);
        $this->assertEquals('HOUSING (OPTIONAL)', $parameters[9]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.4. The campus is well-planned, clean and properly landscaped.', end($rows)['content']);

        $paramJRows = $this->getJson("/api/parameters/{$parameters[9]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('O.2. There is wholesome coordination among the Institution, the LGU\'s and the owners of private boarding houses.', end($paramJRows)['content']);
    }

    public function test_area_9_seeds_four_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area9->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(4, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('LABORATORIES, SHOPS/FACILITIES', $parameters[0]['name']);
        $this->assertEquals('EQUIPMENT AND SUPPLIES', $parameters[1]['name']);
        $this->assertEquals('MAINTENANCE', $parameters[2]['name']);
        $this->assertEquals('D', $parameters[3]['code']);
        $this->assertEquals('SPECIAL PROVISIONS', $parameters[3]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.1. The laboratories and shops are well-equipped, functional and conducive to learning.', end($rows)['content']);

        $paramDRows = $this->getJson("/api/parameters/{$parameters[3]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('O.1. The special provisions in CMO of the program are complied with.', end($paramDRows)['content']);
    }

    public function test_area_10_seeds_eight_parameters_with_instrument_content(): void
    {
        Sanctum::actingAs($this->qa);

        $parameters = $this->getJson("/api/accreditation-areas/{$this->area10->id}/parameters")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(8, $parameters);
        $this->assertEquals('A', $parameters[0]['code']);
        $this->assertEquals('ORGANIZATION', $parameters[0]['name']);
        $this->assertEquals('ACADEMIC ADMINISTRATION', $parameters[1]['name']);
        $this->assertEquals('STUDENT ADMINISTRATION', $parameters[2]['name']);
        $this->assertEquals('FINANCIAL MANAGEMENT', $parameters[3]['name']);
        $this->assertEquals('SUPPLY MANAGEMENT', $parameters[4]['name']);
        $this->assertEquals('RECORDS MANAGEMENT', $parameters[5]['name']);
        $this->assertEquals('INSTITUTIONAL PLANNING AND DEVELOPMENT', $parameters[6]['name']);
        $this->assertEquals('H', $parameters[7]['code']);
        $this->assertEquals('PERFORMANCE OF ADMINISTRATIVE PERSONNEL', $parameters[7]['name']);

        $rows = $this->getJson("/api/parameters/{$parameters[0]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('SYSTEM – INPUTS AND PROCESSES', $rows[0]['content']);
        $this->assertSame('O.1. The institution has a well-designed and functional organizational structure.', end($rows)['content']);

        $paramHRows = $this->getJson("/api/parameters/{$parameters[7]['id']}/rows")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('O.1. The administrative personnel/staff have commendable performance.', end($paramHRows)['content']);
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
