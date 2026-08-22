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

/**
 * Feature: Area Assignment Module — ADAMS (Program Chair UI)
 *
 * Covers the fixed 10-area folder grid source, chair/member/deadline
 * assignment endpoints and the searchable user lookup.
 */
class AreaAssignmentModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $chair;
    private Program $program;
    private AccreditationCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Area In-Charge', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $this->program = Program::factory()->create(['college_id' => $college->id]);

        $this->chair = User::factory()->create();
        $this->chair->assignRole('Program Chair');
        $this->chair->program_id = $this->program->id;
        $this->chair->save();

        $this->program->chair_id = $this->chair->id;
        $this->program->save();

        $this->cycle = AccreditationCycle::factory()->create(['program_id' => $this->program->id]);

        Sanctum::actingAs($this->chair);
    }
public function test_program_chair_areas_seeds_ten_fixed_aaccup_areas(): void
    {
        $response = $this->getJson('/api/program-chair/areas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $areas = $response->json('data');
        $this->assertCount(10, $areas);

        $codes = collect($areas)->pluck('code')->all();
        $this->assertEquals([
            'area-1', 'area-2', 'area-3', 'area-4', 'area-5',
            'area-6', 'area-7', 'area-8', 'area-9', 'area-10',
        ], $codes);

        $first = $areas[0];
        $this->assertEquals('Area 1 – Vision, Mission, Goals and Objectives', $first['name']);
        $this->assertNull($first['chairId']);
        $this->assertNull($first['deadline']);
    }

    public function test_program_chair_areas_is_idempotent(): void
    {
        $this->getJson('/api/program-chair/areas')->assertStatus(200);
        $again = $this->getJson('/api/program-chair/areas');

        $this->assertCount(10, $again->json('data'));

        $seeded = AccreditationArea::where('cycle_id', $this->cycle->id)->whereNotNull('code')->count();
        $this->assertEquals(10, $seeded);
    }

    public function test_assign_chair_replaces_previous_and_removes_chair_from_members(): void
    {
        $areas = $this->getJson('/api/program-chair/areas')->json('data');
        $this->assertCount(10, $areas);
        $area = AccreditationArea::findOrFail($areas[0]['id']);

        $facultyA = User::factory()->create();
        $facultyA->assignRole('Faculty');

        // Add member, then promote same user to chair -> member row removed.
        $this->postJson("/api/accreditation-areas/{$area->id}/set-members", ['user_ids' => [$facultyA->id]])
            ->assertStatus(200)->assertJsonPath('success', true);

        $this->postJson("/api/accreditation-areas/{$area->id}/assign-chair", ['chair_id' => $facultyA->id])
            ->assertStatus(200)->assertJsonPath('success', true);

        $area->refresh();
        $this->assertEquals($facultyA->id, $area->chair_id);
        $this->assertFalse(AreaMember::where('area_id', $area->id)->where('user_id', $facultyA->id)->exists());

        // Re-assign a new chair replaces the old (no stacking).
        $facultyB = User::factory()->create();
        $facultyB->assignRole('Faculty');
        $this->postJson("/api/accreditation-areas/{$area->id}/assign-chair", ['chair_id' => $facultyB->id])
            ->assertStatus(200)->assertJsonPath('success', true);

        $area->refresh();
        $this->assertEquals($facultyB->id, $area->chair_id);
    }
public function test_set_members_excludes_chair_from_member_list(): void
    {
        $areas = $this->getJson('/api/program-chair/areas')->json('data');
        $first = $areas[0];
        $area = AccreditationArea::findOrFail($first['id']);

        $chair = User::factory()->create();
        $chair->assignRole('Faculty');
        $this->postJson("/api/accreditation-areas/{$area->id}/assign-chair", ['chair_id' => $chair->id])
            ->assertStatus(200);

        $member = User::factory()->create();
        $member->assignRole('Faculty');

        // Chair id intentionally included -> must be filtered out.
        $response = $this->postJson("/api/accreditation-areas/{$area->id}/set-members", [
            'user_ids' => [$chair->id, $member->id],
        ]);
        $response->assertStatus(200)->assertJsonPath('success', true);

        $memberIds = collect(AreaMember::where('area_id', $area->id)->pluck('user_id'))->map(fn ($id) => (string) $id);
        $this->assertNotContains((string) $chair->id, $memberIds->all());
        $this->assertContains((string) $member->id, $memberIds->all());
    }

    public function test_set_deadline_persists_value(): void
    {
        $areas = $this->getJson('/api/program-chair/areas')->json('data');
        $area = AccreditationArea::findOrFail($areas[0]['id']);

        $response = $this->postJson("/api/accreditation-areas/{$area->id}/set-deadline", [
            'deadline' => '2026-12-31 17:00:00',
        ]);
        $response->assertStatus(200)->assertJsonPath('success', true);

        $area->refresh();
        $this->assertNotNull($area->deadline);
        $this->assertEquals('2026-12-31 17:00:00', $area->deadline->toDateTimeString());
    }

    public function test_set_members_allows_empty_member_list(): void
    {
        $areas = $this->getJson('/api/program-chair/areas')->json('data');
        $area = AccreditationArea::findOrFail($areas[0]['id']);

        $this->postJson("/api/accreditation-areas/{$area->id}/set-members", ['user_ids' => []])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame(0, AreaMember::where('area_id', $area->id)->count());
    }

    public function test_set_members_updates_role_scope_for_assigned_members(): void
    {
        $areas = $this->getJson('/api/program-chair/areas')->json('data');
        $area = AccreditationArea::findOrFail($areas[0]['id']);

        $member = User::factory()->create();
        $member->assignRole('Faculty');

        $this->postJson("/api/accreditation-areas/{$area->id}/set-members", ['user_ids' => [$member->id]])
            ->assertStatus(200);

        // The member's assignedAreaIds now include this area (auto-permission).
        $member->refresh();
        $assigned = collect($member->assignedAreaIds())->map(fn ($id) => (int) $id)->all();
        $this->assertContains($area->id, $assigned);
    }

    public function test_user_search_lists_matching_faculty(): void
    {
        $faculty = User::factory()->create(['email' => 'area.search.me@example.com']);
        $faculty->assignRole('Faculty');

        $response = $this->getJson('/api/users/search?q=area.search.me');

        $response->assertStatus(200)->assertJsonPath('success', true);
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $faculty->id, $ids);
    }

    public function test_non_program_chair_cannot_list_program_chair_areas(): void
    {
        $intruder = User::factory()->create();
        $intruder->assignRole('Faculty');
        Sanctum::actingAs($intruder);

        $this->getJson('/api/program-chair/areas')->assertStatus(403);
    }
}