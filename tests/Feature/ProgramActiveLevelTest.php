<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProgramActiveLevelTest extends TestCase
{
    use RefreshDatabase;

    private User $chair;
    private Program $program;
    private AccreditationCycle $levelOne;
    private AccreditationCycle $levelTwo;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'QA', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $this->program = Program::factory()->create(['college_id' => $college->id]);
        $this->chair = User::factory()->create(['program_id' => $this->program->id]);
        $this->chair->assignRole('Program Chair');
        $this->program->update(['chair_id' => $this->chair->id]);

        $this->levelOne = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'level' => 'Level I',
            'status' => 'Preparation',
        ]);
        $this->levelTwo = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'level' => 'Level II',
            'status' => 'Planning',
        ]);
    }

    public function test_program_chair_can_set_active_level_from_an_existing_cycle(): void
    {
        Sanctum::actingAs($this->chair);

        $this->putJson("/api/programs/{$this->program->id}/active-level", [
            'level' => 'Level II',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.activeCycleId', $this->levelTwo->id)
            ->assertJsonPath('data.activeLevel', 'Level II');

        $this->assertDatabaseHas('programs', [
            'id' => $this->program->id,
            'active_cycle_id' => $this->levelTwo->id,
            'accreditation_level' => 'Level II',
        ]);
    }

    public function test_setting_a_level_without_a_cycle_does_not_create_one(): void
    {
        Sanctum::actingAs($this->chair);

        $this->putJson("/api/programs/{$this->program->id}/active-level", [
            'level' => 'Level IV',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No cycle yet for Level IV.');

        $this->assertSame(0, AccreditationCycle::where('program_id', $this->program->id)->where('level', 'Level IV')->count());
        $this->assertDatabaseHas('programs', [
            'id' => $this->program->id,
            'active_cycle_id' => null,
        ]);
    }

    public function test_faculty_cannot_set_the_active_level(): void
    {
        $faculty = User::factory()->create(['program_id' => $this->program->id]);
        $faculty->assignRole('Faculty');
        Sanctum::actingAs($faculty);

        $this->putJson("/api/programs/{$this->program->id}/active-level", [
            'cycle_id' => $this->levelOne->id,
        ])->assertStatus(403);
    }

    public function test_faculty_my_areas_are_filtered_to_the_active_cycle(): void
    {
        $faculty = User::factory()->create(['program_id' => $this->program->id]);
        $faculty->assignRole('Faculty');

        $levelOneArea = AccreditationArea::factory()->create([
            'cycle_id' => $this->levelOne->id,
            'code' => 'area-1',
            'chair_id' => $faculty->id,
        ]);
        $levelTwoArea = AccreditationArea::factory()->create([
            'cycle_id' => $this->levelTwo->id,
            'code' => 'area-1',
        ]);
        AreaMember::create([
            'area_id' => $levelTwoArea->id,
            'user_id' => $faculty->id,
            'role' => 'member',
        ]);

        Sanctum::actingAs($faculty);
        $before = $this->getJson('/api/users/me/areas')->assertStatus(200)->json('data');
        $this->assertEqualsCanonicalizing([$levelOneArea->id, $levelTwoArea->id], collect($before)->pluck('id')->all());

        $this->program->update(['active_cycle_id' => $this->levelTwo->id]);

        $after = $this->getJson('/api/users/me/areas')->assertStatus(200)->json('data');
        $this->assertSame([$levelTwoArea->id], collect($after)->pluck('id')->all());
        $this->assertTrue($this->getJson('/api/users/me/areas')->json('meta.lockedToActiveLevel'));
    }

    public function test_active_level_payload_marks_missing_cycles_as_not_selectable(): void
    {
        Sanctum::actingAs($this->chair);

        $levels = $this->getJson("/api/programs/{$this->program->id}/active-level")
            ->assertStatus(200)
            ->json('data.levels');

        $byLevel = collect($levels)->keyBy('level');
        $this->assertTrue($byLevel['Level I']['selectable']);
        $this->assertTrue($byLevel['Level II']['selectable']);
        $this->assertFalse($byLevel['Level III']['selectable']);
        $this->assertFalse($byLevel['Level IV']['selectable']);
    }
}
