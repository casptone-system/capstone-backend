<?php

namespace Tests\Feature;

use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Services\AaccupStructureService;
use App\Support\RoleSlug;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VpaaWorkflowTest extends TestCase
{
    private User $vpaa;

    private User $qa;

    private User $chair;

    private User $faculty;

    private College $college;

    private Program $program;

    private AccreditationCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->college = College::factory()->create(['name' => 'College of Computing']);
        $this->program = Program::factory()->create([
            'college_id' => $this->college->id,
            'name' => 'BSIT',
            'code' => 'BSIT-VPAA',
        ]);
        $this->cycle = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'college_id' => $this->college->id,
            'level' => 'Level I',
            'status' => 'Preparation',
            'phase' => 'Preparation',
            'scheduled_visit' => '2026-10-15',
            'valid_until' => '2027-10-15',
        ]);

        $this->vpaa = User::factory()->create(['college_id' => null, 'program_id' => null]);
        $this->vpaa->assignRole(RoleSlug::VPAA);

        $this->qa = User::factory()->create(['college_id' => null, 'program_id' => null]);
        $this->qa->assignRole(RoleSlug::QA);

        $this->chair = User::factory()->create([
            'college_id' => $this->college->id,
            'program_id' => $this->program->id,
        ]);
        $this->chair->assignRole(RoleSlug::PROGRAM_CHAIR);
        $this->program->update(['chair_id' => $this->chair->id]);

        $this->faculty = User::factory()->create(['program_id' => $this->program->id]);
        $this->faculty->assignRole(RoleSlug::FACULTY);
    }

    public function test_vpaa_can_set_schedule_and_validity(): void
    {
        Sanctum::actingAs($this->vpaa);

        $this->postJson('/api/accreditation-cycles/'.$this->cycle->id.'/set-schedule', [
            'scheduled_visit' => '2026-11-01',
            'valid_until' => '2028-11-01',
        ])->assertOk()
            ->assertJsonPath('data.scheduled_visit', '2026-11-01')
            ->assertJsonPath('data.valid_until', '2028-11-01')
            ->assertJsonPath('data.validity_status', 'Valid');
    }

    public function test_qa_faculty_and_program_chair_cannot_set_schedule(): void
    {
        Sanctum::actingAs($this->qa);
        $this->postJson('/api/accreditation-cycles/'.$this->cycle->id.'/set-schedule', [
            'scheduled_visit' => '2026-12-01',
        ])->assertForbidden();

        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/accreditation-cycles/'.$this->cycle->id.'/set-schedule', [
            'scheduled_visit' => '2026-12-01',
        ])->assertForbidden();

        Sanctum::actingAs($this->chair);
        $this->postJson('/api/accreditation-cycles/'.$this->cycle->id.'/set-schedule', [
            'scheduled_visit' => '2026-12-01',
        ])->assertForbidden();
    }

    public function test_program_chair_can_set_level_and_phase_but_not_dates(): void
    {
        Sanctum::actingAs($this->chair);

        $this->postJson('/api/accreditation-cycles/'.$this->cycle->id.'/program-chair-setup', [
            'level' => 'Level II',
            'phase' => 'Internal Review',
            'scheduled_visit' => '2030-01-01',
            'valid_until' => '2031-01-01',
        ])->assertOk()
            ->assertJsonPath('data.accreditation_cycle.level', 'Level II')
            ->assertJsonPath('data.accreditation_cycle.phase', 'Internal Review')
            ->assertJsonPath('data.accreditation_cycle.scheduled_visit', '2026-10-15')
            ->assertJsonPath('data.accreditation_cycle.valid_until', '2027-10-15');

        $this->putJson('/api/accreditation-cycles/'.$this->cycle->id, [
            'scheduled_visit' => '2030-01-01',
            'valid_until' => '2031-01-01',
            'level' => 'Level III',
        ])->assertOk()
            ->assertJsonPath('data.level', 'Level III')
            ->assertJsonPath('data.scheduled_visit', '2026-10-15')
            ->assertJsonPath('data.valid_until', '2027-10-15');
    }

    public function test_vpaa_cannot_change_level_through_generic_update(): void
    {
        Sanctum::actingAs($this->vpaa);

        $this->putJson('/api/accreditation-cycles/'.$this->cycle->id, [
            'level' => 'Level IV',
            'scheduled_visit' => '2026-12-12',
        ])->assertOk()
            ->assertJsonPath('data.level', 'Level I')
            ->assertJsonPath('data.scheduled_visit', '2026-12-12');
    }

    public function test_expired_valid_until_displays_as_expired_without_changing_stored_status(): void
    {
        $this->cycle->update([
            'status' => 'Completed',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        Sanctum::actingAs($this->vpaa);

        $programs = $this->getJson('/api/accreditation-cycles/level-status?view=vpaa')
            ->assertOk()
            ->json('data');

        $program = collect($programs)->firstWhere('programId', $this->program->id);
        $this->assertNotNull($program);
        $level = collect($program['levels'])->firstWhere('level', 'Level I');
        $this->assertSame('Expired', $level['displayStatus']);
        $this->assertSame('Expired', $level['validityStatus']);
        $this->assertSame('Completed', $this->cycle->fresh()->status);
    }

    public function test_vpaa_dashboard_includes_level_preparation_and_validity(): void
    {
        Sanctum::actingAs($this->vpaa);

        $data = $this->getJson('/api/vpaa/dashboard')->assertOk()->json('data');
        $row = collect($data['accreditations'])->firstWhere('id', $this->cycle->id);

        $this->assertNotNull($row);
        $this->assertSame('Level I', $row['level']);
        $this->assertSame('In Progress', $row['preparation_status']);
        $this->assertSame('Valid', $row['validity_status']);
        $this->assertSame('2027-10-15', $row['valid_until']);
        $this->assertSame('2026-10-15', $row['scheduled_visit']);
        $this->assertArrayHasKey('expired_validity', $data['summary']);
    }

    public function test_vpaa_catalog_lists_each_area_and_parameter_once(): void
    {
        Sanctum::actingAs($this->vpaa);
        app(AaccupStructureService::class)->bootstrapProgram($this->program);

        foreach (['Level II', 'Level III'] as $level) {
            $cycle = AccreditationCycle::factory()->create([
                'program_id' => $this->program->id,
                'college_id' => $this->college->id,
                'level' => $level,
                'status' => 'Planning',
            ]);
            app(AaccupStructureService::class)->seedCycleAreas($cycle);
        }

        $all = $this->getJson('/api/qa/areas')->assertOk()->json('data');
        $catalog = $this->getJson('/api/qa/areas?catalog=1')->assertOk()->json('data');

        $this->assertCount(10, $all);
        $this->assertCount(10, collect($all)->pluck('code')->unique()->values());
        $this->assertCount(10, $catalog);
        $this->assertCount(10, collect($catalog)->pluck('code')->unique()->values());
        $this->assertSame('area-1', $catalog[0]['code']);
        $this->assertSame('area-10', $catalog[9]['code']);

        $parameters = $this->getJson('/api/accreditation-areas/'.$catalog[9]['id'].'/parameters')
            ->assertOk()
            ->json('data');

        $this->assertCount(8, $parameters);
        $this->assertCount(8, collect($parameters)->pluck('code')->unique()->values());
        $this->assertSame('A', $parameters[0]['code']);
        $this->assertSame('H', $parameters[7]['code']);
    }

    public function test_vpaa_lists_one_cycle_per_program(): void
    {
        Sanctum::actingAs($this->vpaa);
        app(AaccupStructureService::class)->bootstrapProgram($this->program);

        foreach (['Level II', 'Level III'] as $level) {
            $cycle = AccreditationCycle::factory()->create([
                'program_id' => $this->program->id,
                'college_id' => $this->college->id,
                'level' => $level,
                'status' => 'Planning',
            ]);
            app(AaccupStructureService::class)->seedCycleAreas($cycle);
        }

        $this->program->update([
            'active_cycle_id' => $this->cycle->id,
            'accreditation_level' => 'Level I',
        ]);

        $schedule = $this->getJson('/api/accreditation-cycles?active_only=1&per_page=200')->assertOk()->json('data');
        $scheduleRows = collect($schedule)->filter(fn ($row) => is_array($row))->values();
        if ($scheduleRows->isEmpty() && is_array($schedule)) {
            $scheduleRows = collect($schedule['data'] ?? $schedule);
        }

        $this->assertCount(1, collect($scheduleRows)->where('program_id', $this->program->id)->values());

        $dashboard = $this->getJson('/api/vpaa/dashboard')->assertOk()->json('data');
        $rows = collect($dashboard['accreditations'])->where('program_id', $this->program->id)->values();
        $this->assertCount(1, $rows);
        $this->assertSame('Level I', $rows->first()['level']);
    }

    public function test_vpaa_can_upsert_instruments_and_qa_cannot(): void
    {
        Sanctum::actingAs($this->vpaa);
        $this->postJson('/api/instrument-templates', [
            'name' => 'Level I Instrument',
            'level' => 'Level I',
            'areas' => [['name' => 'Vision, Mission, Goals and Objectives']],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Level I Instrument');

        Sanctum::actingAs($this->qa);
        $this->postJson('/api/instrument-templates', [
            'name' => 'QA rewrite',
            'level' => 'Level I',
            'areas' => [['name' => 'Area 1']],
        ])->assertForbidden();
    }
}
