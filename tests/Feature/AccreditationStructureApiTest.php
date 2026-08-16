<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationInstrument;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccreditationStructureApiTest extends TestCase
{
    use RefreshDatabase;

    private User $vpaa;
    private User $dean;
    private User $programChair;
    private User $areaInCharge;
    private Program $program;
    private AccreditationCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users with different roles
        $this->vpaa = User::factory()->create();
        $this->vpaa->assignRole('VPAA');

        $college = \App\Models\College::factory()->create();

        $this->dean = User::factory()->create(['college_id' => $college->id]);
        $this->dean->assignRole('Dean');

        $this->program = Program::factory()->create(['college_id' => $college->id]);

        $this->programChair = User::factory()->create(['college_id' => $college->id, 'program_id' => $this->program->id]);
        $this->programChair->assignRole('Program Chair');
        $this->program->update(['chair_id' => $this->programChair->id]);

        $this->areaInCharge = User::factory()->create(['college_id' => $college->id, 'program_id' => $this->program->id]);
        $this->areaInCharge->assignRole('Area In-Charge');

        // Create accreditation cycle
        $this->cycle = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'college_id' => $college->id,
            'workflow_status' => 'Forwarded to Chair',
            'level' => 'Level III',
            'phase' => 'Preparation',
        ]);
    }

    /**
     * STAGE 2: Accreditation Structure Tests
     */

    public function test_vpaa_can_create_instrument_structure_with_areas_and_requirements(): void
    {
        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure', [
                'name' => 'ACSUCTE Accreditation Instrument 2026',
                'version' => '2026.1',
                'description' => 'Official accreditation instrument for teacher education programs.',
                'areas' => [
                    [
                        'name' => 'Area I: Vision, Mission and Goals',
                        'description' => 'The program has a clearly articulated vision, mission and goals.',
                        'requirements' => [
                            [
                                'code' => '1.1',
                                'title' => 'Vision Statement',
                                'description' => 'The program must have a clear vision statement.',
                                'evidence_guidance' => 'Provide the vision statement and supporting documentation.',
                                'required_evidence_type' => 'Document',
                            ],
                            [
                                'code' => '1.2',
                                'title' => 'Mission Statement',
                                'description' => 'The program must have a clear mission statement.',
                                'evidence_guidance' => 'Provide the mission statement and supporting documentation.',
                                'required_evidence_type' => 'Document',
                            ],
                        ],
                    ],
                    [
                        'name' => 'Area II: Faculty',
                        'description' => 'The program faculty meet established qualifications.',
                        'requirements' => [
                            [
                                'code' => '2.1',
                                'title' => 'Faculty Qualifications',
                                'description' => 'Faculty must have appropriate qualifications.',
                                'evidence_guidance' => 'Provide faculty CVs and credentials.',
                                'required_evidence_type' => 'Document',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.instrument.name', 'ACSUCTE Accreditation Instrument 2026')
            ->assertJsonPath('data.instrument.version', '2026.1')
            ->assertJsonPath('data.cycle.level', 'Level III')
            ->assertJsonPath('data.cycle.phase', 'Preparation');

        $this->assertDatabaseHas('accreditation_instruments', [
            'name' => 'ACSUCTE Accreditation Instrument 2026',
            'version' => '2026.1',
        ]);

        $this->assertDatabaseHas('accreditation_areas', [
            'cycle_id' => $this->cycle->id,
            'name' => 'Area I: Vision, Mission and Goals',
        ]);

        $this->assertDatabaseHas('accreditation_requirements', [
            'code' => '1.1',
            'title' => 'Vision Statement',
        ]);
    }

    public function test_non_vpaa_cannot_create_instrument_structure(): void
    {
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure', [
                'name' => 'Instrument',
                'areas' => [],
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only the VPAA/DI can create an accreditation instrument structure.');
    }

    public function test_program_chair_can_view_accreditation_structure(): void
    {
        $instrument = AccreditationInstrument::factory()->create([
            'name' => 'Teacher Education Instrument',
        ]);

        $this->cycle->update(['instrument_id' => $instrument->id]);

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
            'name' => 'Area I: Vision',
        ]);

        $area->requirements()->create([
            'code' => '1.1',
            'title' => 'Vision Statement',
            'status' => 'Not Started',
        ]);

        $this->actingAs($this->programChair, 'sanctum')
            ->getJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.instrument.name', 'Teacher Education Instrument')
            ->assertJsonPath('data.cycle.level', 'Level III')
            ->assertJsonPath('data.cycle.phase', 'Preparation')
            ->assertJsonPath('data.cycle.workflow_status', 'Forwarded to Chair');
    }

    public function test_dean_can_view_accreditation_structure(): void
    {
        $instrument = AccreditationInstrument::factory()->create();
        $this->cycle->update(['instrument_id' => $instrument->id]);

        $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_area_in_charge_can_view_assigned_area_structure(): void
    {
        $instrument = AccreditationInstrument::factory()->create();

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
            'name' => 'Area I: Vision',
            'chair_id' => $this->areaInCharge->id,
        ]);

        $this->actingAs($this->areaInCharge, 'sanctum')
            ->getJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_unauthorized_user_cannot_view_structure(): void
    {
        $other = User::factory()->create();
        $other->assignRole('Faculty');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure')
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are not authorized to view this accreditation structure.');
    }

    public function test_program_chair_can_assign_area_in_charge(): void
    {
        $instrument = AccreditationInstrument::factory()->create();

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
            'name' => 'Area II: Faculty',
        ]);

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-areas/' . $area->id . '/assign-in-charge', [
                'chair_id' => $this->areaInCharge->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Area In-Charge assigned successfully.')
            ->assertJsonPath('data.chairId', $this->areaInCharge->id);

        $this->assertDatabaseHas('accreditation_areas', [
            'id' => $area->id,
            'chair_id' => $this->areaInCharge->id,
        ]);
    }

    public function test_area_in_charge_receives_notification_on_assignment(): void
    {
        $instrument = AccreditationInstrument::factory()->create();

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
            'name' => 'Area III: Student Assessment',
        ]);

        \Illuminate\Support\Facades\Notification::fake();

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-areas/' . $area->id . '/assign-in-charge', [
                'chair_id' => $this->areaInCharge->id,
            ]);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $this->areaInCharge,
            \App\Notifications\AreaInChargeAssignedNotification::class
        );
    }

    public function test_non_program_chair_cannot_assign_area_in_charge(): void
    {
        $instrument = AccreditationInstrument::factory()->create();

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
        ]);

        $this->actingAs($this->areaInCharge, 'sanctum')
            ->postJson('/api/accreditation-areas/' . $area->id . '/assign-in-charge', [
                'chair_id' => $this->areaInCharge->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only the assigned Program Chair may manage this accreditation structure.');
    }

    public function test_program_chair_cannot_assign_non_area_in_charge_user(): void
    {
        $instrument = AccreditationInstrument::factory()->create();

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
        ]);

        $faculty = User::factory()->create(['program_id' => $this->program->id]);
        $faculty->assignRole('Faculty');

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-areas/' . $area->id . '/assign-in-charge', [
                'chair_id' => $faculty->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected user must be an Area In-Charge assigned to this program.');
    }

    public function test_program_chair_cannot_assign_area_in_charge_from_different_program(): void
    {
        $otherCollege = \App\Models\College::factory()->create();
        $otherProgram = Program::factory()->create(['college_id' => $otherCollege->id]);

        $otherAreaInCharge = User::factory()->create(['college_id' => $otherCollege->id, 'program_id' => $otherProgram->id]);
        $otherAreaInCharge->assignRole('Area In-Charge');

        $instrument = AccreditationInstrument::factory()->create();

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
        ]);

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-areas/' . $area->id . '/assign-in-charge', [
                'chair_id' => $otherAreaInCharge->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected user must be an Area In-Charge assigned to this program.');
    }

    public function test_program_chair_can_get_area_requirements(): void
    {
        $instrument = AccreditationInstrument::factory()->create();

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $instrument->id,
            'name' => 'Area I',
        ]);

        $area->requirements()->createMany([
            [
                'code' => '1.1',
                'title' => 'Vision Statement',
                'status' => 'Not Started',
            ],
            [
                'code' => '1.2',
                'title' => 'Mission Statement',
                'status' => 'Not Started',
            ],
        ]);

        $this->actingAs($this->programChair, 'sanctum')
            ->getJson('/api/accreditation-areas/' . $area->id . '/requirements')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_structure_shows_cycle_details_level_phase_and_workflow_status(): void
    {
        $instrument = AccreditationInstrument::factory()->create();
        $this->cycle->update(['instrument_id' => $instrument->id]);

        $this->actingAs($this->programChair, 'sanctum')
            ->getJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure')
            ->assertStatus(200)
            ->assertJsonPath('data.cycle.level', 'Level III')
            ->assertJsonPath('data.cycle.phase', 'Preparation')
            ->assertJsonPath('data.cycle.workflow_status', 'Forwarded to Chair')
            ->assertJsonPath('data.cycle.program_id', $this->program->id);
    }

    public function test_audit_logs_instrument_creation(): void
    {
        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $this->cycle->id . '/structure', [
                'name' => 'Test Instrument',
                'areas' => [
                    [
                        'name' => 'Area I',
                        'requirements' => [
                            [
                                'code' => '1.1',
                                'title' => 'Requirement 1',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->vpaa->id,
            'event' => 'create',
            'path' => 'api/accreditation-cycles/' . $this->cycle->id . '/structure',
            'status' => 'success',
        ]);
    }
}
