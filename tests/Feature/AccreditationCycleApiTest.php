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
        $program = Program::factory()->create();

        $response = $this->postJson('/api/accreditation-cycles', [
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
        $response = $this->postJson('/api/accreditation-cycles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_id', 'level', 'status']);
    }

    public function test_store_validates_level_enum(): void
    {
        $program = Program::factory()->create();

        $response = $this->postJson('/api/accreditation-cycles', [
            'program_id' => $program->id,
            'level' => 'Invalid Level',
            'status' => 'Planning',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['level']);
    }

    public function test_store_validates_status_enum(): void
    {
        $program = Program::factory()->create();

        $response = $this->postJson('/api/accreditation-cycles', [
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
            ->assertJsonPath('data.level', 'Level II')
            ->assertJsonPath('data.status', 'Ready')
            ->assertJsonPath('data.validUntil', '2026-06-30')
            ->assertJsonPath('data.readiness', 'Ready');

        $this->assertDatabaseHas('accreditation_cycles', [
            'id' => $cycle->id,
            'level' => 'Level II',
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
        auth()->forgetGuards();

        $response = $this->getJson('/api/accreditation-cycles');

        $response->assertStatus(401);
    }
}
