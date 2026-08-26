<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Services\AaccupStructureService;
use App\Support\RoleSlug;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramChairAreaAssignmentTest extends TestCase
{
    public function test_program_chair_opens_current_level_and_above_and_marks_lower_reached(): void
    {
        $college = College::factory()->create();
        $chair = User::factory()->create([
            'college_id' => $college->id,
        ]);
        $chair->assignRole(RoleSlug::PROGRAM_CHAIR);

        $program = Program::factory()->create([
            'college_id' => $college->id,
            'chair_id' => $chair->id,
            'accreditation_level' => 'Level II',
        ]);
        $chair->update(['program_id' => $program->id]);

        $structure = app(AaccupStructureService::class);
        $levelI = $structure->ensureCycle($program, 'Level I');
        $levelII = $structure->ensureCycle($program, 'Level II');
        $program->update(['active_cycle_id' => $levelII->id]);

        $faculty = User::factory()->create(['program_id' => $program->id]);
        $faculty->assignRole(RoleSlug::FACULTY);

        Sanctum::actingAs($chair);
        $levels = $this->getJson('/api/program-chair/areas')
            ->assertOk()
            ->json('data.levels');

        $byLevel = collect($levels)->keyBy('level');

        $this->assertSame('reached', $byLevel['Level I']['access']);
        $this->assertSame('Reached', $byLevel['Level I']['displayStatus']);
        $this->assertFalse($byLevel['Level I']['isOpen']);
        $this->assertSame($levelI->id, $byLevel['Level I']['cycleId']);
        $this->assertSame([], $byLevel['Level I']['areas']);

        $this->assertSame('open', $byLevel['Level II']['access']);
        $this->assertTrue($byLevel['Level II']['isOpen']);
        $this->assertSame($levelII->id, $byLevel['Level II']['cycleId']);
        $this->assertCount(10, $byLevel['Level II']['areas']);
        $this->assertNotEmpty($byLevel['Level II']['areas'][0]['id']);

        $this->assertSame('open', $byLevel['Level III']['access']);
        $this->assertTrue($byLevel['Level III']['isOpen']);
        $this->assertNotNull($byLevel['Level III']['cycleId']);
        $this->assertCount(10, $byLevel['Level III']['areas']);

        $this->assertSame('open', $byLevel['Level IV']['access']);
        $this->assertTrue($byLevel['Level IV']['isOpen']);
        $this->assertNotNull($byLevel['Level IV']['cycleId']);
        $this->assertCount(10, $byLevel['Level IV']['areas']);

        $levelIAreaId = $levelI->areas()->where('code', 'area-1')->value('id');
        $this->postJson("/api/accreditation-areas/{$levelIAreaId}/assign-chair", [
            'chair_id' => $faculty->id,
        ])->assertStatus(422);

        $levelIIAreaId = $byLevel['Level II']['areas'][0]['id'];
        $this->postJson("/api/accreditation-areas/{$levelIIAreaId}/assign-chair", [
            'chair_id' => $faculty->id,
        ])->assertOk();
    }
}
