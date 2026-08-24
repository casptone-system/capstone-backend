<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccreditationLevelStatusTest extends TestCase
{
    public function test_display_status_mapping_from_cycle_status(): void
    {
        $this->assertSame('Accredited', AccreditationCycle::mapDisplayStatus('Completed'));
        $this->assertSame('In Progress', AccreditationCycle::mapDisplayStatus('Preparation'));
        $this->assertSame('In Progress', AccreditationCycle::mapDisplayStatus('Internal Review'));
        $this->assertSame('In Progress', AccreditationCycle::mapDisplayStatus('Ready'));
        $this->assertSame('Not Started', AccreditationCycle::mapDisplayStatus('Planning'));
        $this->assertSame('Expired', AccreditationCycle::mapDisplayStatus('Expired'));
        $this->assertSame('Not Started', AccreditationCycle::mapDisplayStatus(null));
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/accreditation-cycles/level-status')->assertUnauthorized();
    }

    public function test_faculty_cannot_request_dean_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Faculty');
        Sanctum::actingAs($user);

        $this->getJson('/api/accreditation-cycles/level-status?view=dean')
            ->assertForbidden();
    }

    public function test_returns_all_four_levels_per_program_not_only_latest_cycle(): void
    {
        $program = Program::factory()->create();
        $faculty = User::factory()->create(['program_id' => $program->id]);
        $faculty->assignRole('Faculty');
        Sanctum::actingAs($faculty);

        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $program->college_id,
            'level' => 'Level I',
            'status' => 'Completed',
        ]);
        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $program->college_id,
            'level' => 'Level II',
            'status' => 'Preparation',
        ]);
        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $program->college_id,
            'level' => 'Level IV',
            'status' => 'Expired',
        ]);

        $response = $this->getJson('/api/accreditation-cycles/level-status?view=faculty');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.programId', $program->id)
            ->assertJsonPath('data.0.levels.0.level', 'Level I')
            ->assertJsonPath('data.0.levels.0.displayStatus', 'Accredited')
            ->assertJsonPath('data.0.levels.1.level', 'Level II')
            ->assertJsonPath('data.0.levels.1.displayStatus', 'In Progress')
            ->assertJsonPath('data.0.levels.2.level', 'Level III')
            ->assertJsonPath('data.0.levels.2.displayStatus', 'Not Started')
            ->assertJsonPath('data.0.levels.3.level', 'Level IV')
            ->assertJsonPath('data.0.levels.3.displayStatus', 'Expired');
    }

    public function test_uses_latest_cycle_when_a_level_has_duplicates(): void
    {
        $program = Program::factory()->create();
        $faculty = User::factory()->create(['program_id' => $program->id]);
        $faculty->assignRole('Faculty');
        Sanctum::actingAs($faculty);

        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $program->college_id,
            'level' => 'Level I',
            'status' => 'Planning',
            'created_at' => now()->subDays(10),
        ]);
        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $program->college_id,
            'level' => 'Level I',
            'status' => 'Completed',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/accreditation-cycles/level-status?view=faculty');

        $response->assertOk()
            ->assertJsonPath('data.0.levels.0.displayStatus', 'Accredited')
            ->assertJsonPath('data.0.levels.0.cycleStatus', 'Completed');
    }

    public function test_dean_only_sees_programs_in_their_college(): void
    {
        $college = College::factory()->create();
        $otherCollege = College::factory()->create();
        $visible = Program::factory()->create(['college_id' => $college->id]);
        $hidden = Program::factory()->create(['college_id' => $otherCollege->id]);

        AccreditationCycle::factory()->create([
            'program_id' => $visible->id,
            'college_id' => $college->id,
            'level' => 'Level I',
            'status' => 'Ready',
        ]);
        AccreditationCycle::factory()->create([
            'program_id' => $hidden->id,
            'college_id' => $otherCollege->id,
            'level' => 'Level I',
            'status' => 'Completed',
        ]);

        $dean = User::factory()->create(['college_id' => $college->id]);
        $dean->assignRole('Dean');
        Sanctum::actingAs($dean);

        $response = $this->getJson('/api/accreditation-cycles/level-status?view=dean');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($visible->id, $response->json('data.0.programId'));
        $this->assertSame('In Progress', $response->json('data.0.levels.0.displayStatus'));
    }

    public function test_program_chair_only_sees_assigned_program(): void
    {
        $program = Program::factory()->create();
        $other = Program::factory()->create();
        $chair = User::factory()->create(['program_id' => $program->id]);
        $chair->assignRole('Program Chair');
        $program->update(['chair_id' => $chair->id]);

        AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $program->college_id,
            'level' => 'Level II',
            'status' => 'Internal Review',
        ]);
        AccreditationCycle::factory()->create([
            'program_id' => $other->id,
            'college_id' => $other->college_id,
            'level' => 'Level II',
            'status' => 'Completed',
        ]);

        Sanctum::actingAs($chair);

        $response = $this->getJson('/api/accreditation-cycles/level-status?view=program-chair');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($program->id, $response->json('data.0.programId'));
        $this->assertSame('In Progress', $response->json('data.0.levels.1.displayStatus'));
    }

    public function test_qa_sees_institution_wide_programs(): void
    {
        Program::factory()->count(2)->create();

        $qa = User::factory()->create();
        $qa->assignRole('QA');
        Sanctum::actingAs($qa);

        $this->getJson('/api/accreditation-cycles/level-status?view=qa')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_area_in_charge_only_sees_programs_for_assigned_areas(): void
    {
        $assignedProgram = Program::factory()->create();
        $otherProgram = Program::factory()->create();
        $inCharge = User::factory()->create();
        $inCharge->assignRole('Area In-Charge');

        $assignedCycle = AccreditationCycle::factory()->create([
            'program_id' => $assignedProgram->id,
            'college_id' => $assignedProgram->college_id,
            'level' => 'Level I',
            'status' => 'Planning',
        ]);
        AccreditationCycle::factory()->create([
            'program_id' => $otherProgram->id,
            'college_id' => $otherProgram->college_id,
            'level' => 'Level I',
            'status' => 'Completed',
        ]);

        AccreditationArea::factory()->create([
            'cycle_id' => $assignedCycle->id,
            'chair_id' => $inCharge->id,
            'name' => 'Area 1 – Vision, Mission, Goals and Objectives',
        ]);

        Sanctum::actingAs($inCharge);

        $response = $this->getJson('/api/accreditation-cycles/level-status?view=area-incharge');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($assignedProgram->id, $response->json('data.0.programId'));
        $this->assertSame('Not Started', $response->json('data.0.levels.0.displayStatus'));
    }
}
