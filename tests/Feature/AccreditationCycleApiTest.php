<?php

namespace Tests\Feature;

use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccreditationCycleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_index_returns_paginated_accreditation_cycles(): void
    {
        $program = Program::factory()->create();
        AccreditationCycle::factory(3)->create(['program_id' => $program->id]);

        $response = $this->getJson('/api/accreditation-cycles');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'programId', 'level', 'status', 'validUntil', 'scheduledVisit', 'remarks', 'readiness', 'createdAt', 'updatedAt'],
                ],
            ]);
    }

    public function test_index_can_filter_by_program_id(): void
    {
        $programA = Program::factory()->create();
        $programB = Program::factory()->create();
        AccreditationCycle::factory(2)->create(['program_id' => $programA->id]);
        AccreditationCycle::factory(1)->create(['program_id' => $programB->id]);

        $response = $this->getJson('/api/accreditation-cycles?program_id=' . $programA->id);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_can_filter_by_level(): void
    {
        $program = Program::factory()->create();
        AccreditationCycle::factory(2)->create(['program_id' => $program->id, 'level' => 'Level III']);
        AccreditationCycle::factory(1)->create(['program_id' => $program->id, 'level' => 'Level I']);

        $response = $this->getJson('/api/accreditation-cycles?level=Level III');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_can_filter_by_status(): void
    {
        $program = Program::factory()->create();
        AccreditationCycle::factory(2)->create(['program_id' => $program->id, 'status' => 'Ready']);
        AccreditationCycle::factory(1)->create(['program_id' => $program->id, 'status' => 'Planning']);

        $response = $this->getJson('/api/accreditation-cycles?status=Ready');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_store_creates_accreditation_cycle(): void
    {
        $this->user->assignRole('VPAA');
        $program = Program::factory()->create();

        $response = $this->postJson('/api/accreditation-cycles', [
            'college_id' => $program->college_id,
            'program_id' => $program->id,
            'level' => 'Level III',
            'status' => 'Planning',
            'valid_until' => '2025-12-31',
            'scheduled_visit' => '2025-06-15',
            'remarks' => 'Initial planning phase',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.level', 'Level III')
            ->assertJsonPath('data.status', 'Planning')
            ->assertJsonPath('data.validUntil', '2025-12-31')
            ->assertJsonPath('data.scheduledVisit', '2025-06-15')
            ->assertJsonPath('data.remarks', 'Initial planning phase')
            ->assertJsonPath('data.readiness', 'Not Ready');
    }

    public function test_store_validates_required_fields(): void
    {
        $this->user->assignRole('VPAA');
        $response = $this->postJson('/api/accreditation-cycles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['college_id', 'program_id', 'status']);
    }

    public function test_vpaa_can_create_cycle_for_valid_college_program_pair(): void
    {
        $this->user->assignRole('VPAA');
        $college = \App\Models\College::factory()->create();
        $program = \App\Models\Program::factory()->create(['college_id' => $college->id]);

        $response = $this->postJson('/api/accreditation-cycles', [
            'college_id' => $college->id,
            'program_id' => $program->id,
            'level' => 'Level III',
            'phase' => 'Formal Survey',
            'status' => 'Preparation',
            'valid_until' => '2026-09-15',
            'scheduled_visit' => '2026-10-15',
            'instrument_name' => 'Accreditation Instrument 2026.pdf',
            'remarks' => 'Institutional preparation notice for the Dean.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.programId', $program->id)
            ->assertJsonPath('data.collegeId', $college->id)
            ->assertJsonPath('data.phase', 'Formal Survey')
            ->assertJsonPath('data.instrumentName', 'Accreditation Instrument 2026.pdf');
    }

    public function test_invalid_college_program_combination_is_rejected(): void
    {
        $this->user->assignRole('VPAA');
        $collegeA = \App\Models\College::factory()->create();
        $collegeB = \App\Models\College::factory()->create();
        $program = \App\Models\Program::factory()->create(['college_id' => $collegeA->id]);

        $response = $this->postJson('/api/accreditation-cycles', [
            'college_id' => $collegeB->id,
            'program_id' => $program->id,
            'level' => 'Level III',
            'phase' => 'Formal Survey',
            'status' => 'Preparation',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_id']);
    }

    public function test_non_vpaa_cannot_create_accreditation_cycle(): void
    {
        $this->user->assignRole('Faculty');
        $college = \App\Models\College::factory()->create();
        $program = \App\Models\Program::factory()->create(['college_id' => $college->id]);

        $response = $this->postJson('/api/accreditation-cycles', [
            'college_id' => $college->id,
            'program_id' => $program->id,
            'level' => 'Level III',
            'phase' => 'Formal Survey',
            'status' => 'Preparation',
        ]);

        $response->assertStatus(403);
    }

    public function test_dean_can_acknowledge_and_forward_cycle_to_program_chair(): void
    {
        $dean = User::factory()->create();
        $dean->assignRole('Dean');
        $college = \App\Models\College::factory()->create();
        $dean->college_id = $college->id;
        $dean->save();

        $program = Program::factory()->create(['college_id' => $college->id, 'chair_id' => null]);
        $chair = User::factory()->create(['college_id' => $college->id, 'program_id' => $program->id]);
        $chair->assignRole('Program Chair');
        $program->chair_id = $chair->id;
        $program->save();

        $vpaa = User::factory()->create();
        $vpaa->assignRole('VPAA');

        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'phase' => 'Self-Study',
            'workflow_status' => 'Initial Notice',
            'status' => 'Preparation',
        ]);

        $this->actingAs($dean, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $cycle->id . '/acknowledge', [
                'remarks' => 'Dean accepted the accreditation notice and will forward it to the chair.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_status', 'Dean Acknowledged')
            ->assertJsonPath('data.phase', 'Self-Study')
            ->assertJsonPath('data.acknowledgedBy', $dean->id);

        $this->actingAs($dean, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $cycle->id . '/forward-to-chair', [
                'remarks' => 'Forwarded to program chair for requirement setup.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_status', 'Forwarded to Chair')
            ->assertJsonPath('data.phase', 'Self-Study')
            ->assertJsonPath('data.programChairId', $chair->id)
            ->assertJsonPath('data.forwardedBy', $dean->id);

        $this->assertDatabaseHas('accreditation_cycles', [
            'id' => $cycle->id,
            'phase' => 'Self-Study',
            'workflow_status' => 'Forwarded to Chair',
            'acknowledged_by' => $dean->id,
            'forwarded_by' => $dean->id,
            'program_chair_id' => $chair->id,
        ]);
    }

    public function test_program_chair_setup_requires_level_but_allows_optional_phase_and_preserves_workflow_status(): void
    {
        $college = \App\Models\College::factory()->create();
        $chair = User::factory()->create(['college_id' => $college->id]);
        $chair->assignRole('Program Chair');
        $program = Program::factory()->create(['college_id' => $college->id, 'chair_id' => $chair->id]);

        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'phase' => 'Preparation',
            'workflow_status' => 'Forwarded to Chair',
            'status' => 'Preparation',
            'level' => 'Level II',
        ]);

        $this->actingAs($chair, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $cycle->id . '/program-chair-setup', [
                'level' => 'Level III',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.accreditation_cycle.level', 'Level III')
            ->assertJsonPath('data.accreditation_cycle.phase', 'Preparation')
            ->assertJsonPath('data.accreditation_cycle.workflow_status', 'Forwarded to Chair');

        $this->assertDatabaseHas('accreditation_cycles', [
            'id' => $cycle->id,
            'level' => 'Level III',
            'phase' => 'Preparation',
            'workflow_status' => 'Forwarded to Chair',
        ]);
    }

    public function test_program_chair_and_dean_can_advance_cycle_through_validation(): void
    {
        $college = \App\Models\College::factory()->create();
        $chair = User::factory()->create(['college_id' => $college->id]);
        $chair->assignRole('Program Chair');
        $program = Program::factory()->create(['college_id' => $college->id, 'chair_id' => $chair->id]);

        $dean = User::factory()->create(['college_id' => $college->id]);
        $dean->assignRole('Dean');

        $vpaa = User::factory()->create();
        $vpaa->assignRole('VPAA');

        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'phase' => 'Self-Study',
            'workflow_status' => 'Forwarded to Chair',
            'status' => 'Preparation',
        ]);

        $this->actingAs($chair, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $cycle->id . '/set-requirements', [
                'remarks' => 'Requirements established and faculty assigned.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_status', 'Requirements Set')
            ->assertJsonPath('data.phase', 'Self-Study');

        $this->actingAs($dean, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $cycle->id . '/dean-validate', [
                'remarks' => 'Dean validated the cycle for institutional monitoring.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_status', 'Dean Validated')
            ->assertJsonPath('data.phase', 'Self-Study');

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $cycle->id . '/vpaa-monitor', [
                'remarks' => 'VPAA monitoring state recorded.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_status', 'VPAA Monitoring')
            ->assertJsonPath('data.phase', 'Self-Study');
    }

    public function test_non_dean_or_non_chair_cannot_advance_cycle_phase(): void
    {
        $college = \App\Models\College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $faculty = User::factory()->create();
        $faculty->assignRole('Faculty');
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'phase' => 'Initial Notice',
            'status' => 'Preparation',
        ]);

        $this->actingAs($faculty, 'sanctum')
            ->postJson('/api/accreditation-cycles/' . $cycle->id . '/acknowledge')
            ->assertStatus(403);
    }

    public function test_vpaa_can_access_institutional_dashboard(): void
    {
        $vpaa = User::factory()->create();
        $vpaa->assignRole('VPAA');

        $college = \App\Models\College::factory()->create(['name' => 'College of Engineering']);
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
            'compliance_score' => 87,
        ]);

        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'level' => 'Level III',
            'phase' => 'Preparation',
            'status' => 'Preparation',
            'scheduled_visit' => now()->addDays(15),
        ]);

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/vpaa/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.active_accreditations', 1)
            ->assertJsonPath('data.accreditations.0.program', 'BS Information Technology');
    }

    public function test_non_vpaa_cannot_access_institutional_dashboard(): void
    {
        $faculty = User::factory()->create();
        $faculty->assignRole('Faculty');

        $this->actingAs($faculty, 'sanctum')
            ->getJson('/api/vpaa/dashboard')
            ->assertStatus(403);
    }

    public function test_store_validates_level_enum(): void
    {
        $this->user->assignRole('VPAA');
        $program = Program::factory()->create();

        $response = $this->postJson('/api/accreditation-cycles', [
            'college_id' => $program->college_id,
            'program_id' => $program->id,
            'level' => 'Invalid Level',
            'status' => 'Planning',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['level']);
    }

    public function test_store_validates_status_enum(): void
    {
        $this->user->assignRole('VPAA');
        $program = Program::factory()->create();

        $response = $this->postJson('/api/accreditation-cycles', [
            'college_id' => $program->college_id,
            'program_id' => $program->id,
            'level' => 'Level I',
            'status' => 'Invalid Status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_show_returns_accreditation_cycle(): void
    {
        $program = Program::factory()->create();
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'level' => 'Level II',
            'status' => 'Ready',
        ]);

        $response = $this->getJson('/api/accreditation-cycles/' . $cycle->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $cycle->id)
            ->assertJsonPath('data.level', 'Level II')
            ->assertJsonPath('data.status', 'Ready')
            ->assertJsonPath('data.readiness', 'Ready')
            ->assertJsonPath('data.program.id', $program->id);
    }

    public function test_update_modifies_accreditation_cycle(): void
    {
        $program = Program::factory()->create();
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'level' => 'Level I',
            'status' => 'Planning',
        ]);

        $response = $this->patchJson('/api/accreditation-cycles/' . $cycle->id, [
            'level' => 'Level II',
            'status' => 'Ready',
            'valid_until' => '2026-06-30',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.level', 'Level I')
            ->assertJsonPath('data.status', 'Ready')
            ->assertJsonPath('data.validUntil', '2026-06-30')
            ->assertJsonPath('data.readiness', 'Ready');

        $this->assertDatabaseHas('accreditation_cycles', [
            'id' => $cycle->id,
            'level' => 'Level I',
            'status' => 'Ready',
        ]);
    }

    public function test_destroy_deletes_accreditation_cycle(): void
    {
        $program = Program::factory()->create();
        $cycle = AccreditationCycle::factory()->create(['program_id' => $program->id]);

        $response = $this->deleteJson('/api/accreditation-cycles/' . $cycle->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('accreditation_cycles', ['id' => $cycle->id]);
    }

    public function test_history_returns_program_accreditation_history(): void
    {
        $program = Program::factory()->create();
        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'level' => 'Level I',
            'status' => 'Completed',
            'valid_until' => '2023-12-31',
        ]);
        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'level' => 'Level II',
            'status' => 'Ready',
            'valid_until' => '2025-12-31',
        ]);

        $response = $this->getJson('/api/accreditation-cycles/history/' . $program->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_dashboard_returns_metrics(): void
    {
        $program = Program::factory()->create();
        AccreditationCycle::factory(2)->create(['program_id' => $program->id]);

        $response = $this->getJson('/api/accreditation-cycles/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'programs' => [
                        '*' => ['programId', 'programName', 'programCode', 'currentLevel', 'expiryDate', 'readiness'],
                    ],
                    'summary' => ['totalPrograms', 'totalCycles', 'cyclesByStatus', 'cyclesByLevel'],
                ],
            ]);
    }

    public function test_dashboard_shows_current_level_expiry_and_readiness(): void
    {
        $program = Program::factory()->create();
        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'level' => 'Level III',
            'status' => 'Ready',
            'valid_until' => '2025-12-31',
        ]);

        $response = $this->getJson('/api/accreditation-cycles/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.programs.0.currentLevel', 'Level III')
            ->assertJsonPath('data.programs.0.expiryDate', '2025-12-31')
            ->assertJsonPath('data.programs.0.readiness', 'Ready');
    }

    public function test_dashboard_shows_not_started_for_program_without_cycles(): void
    {
        Program::factory()->create();

        $response = $this->getJson('/api/accreditation-cycles/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.programs.0.currentLevel', 'N/A')
            ->assertJsonPath('data.programs.0.expiryDate', null)
            ->assertJsonPath('data.programs.0.readiness', 'Not Started');
    }

    public function test_readiness_accessor_maps_statuses_correctly(): void
    {
        $program = Program::factory()->create();

        $statuses = [
            'Planning' => 'Not Ready',
            'Preparation' => 'In Progress',
            'Internal Review' => 'In Review',
            'Ready' => 'Ready',
            'Completed' => 'Completed',
            'Expired' => 'Expired',
        ];

        foreach ($statuses as $status => $expectedReadiness) {
            $cycle = AccreditationCycle::factory()->create([
                'program_id' => $program->id,
                'status' => $status,
            ]);

            $this->assertEquals($expectedReadiness, $cycle->readiness);
        }
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/accreditation-cycles')
            ->assertStatus(401);
    }
}
