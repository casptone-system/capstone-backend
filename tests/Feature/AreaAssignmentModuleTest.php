<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AreaInChargeAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

        $this->cycle = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'level' => 'Level I',
            'status' => 'Preparation',
        ]);

        Sanctum::actingAs($this->chair);
    }

    private function areasFromResponse($response = null): array
    {
        $payload = $response
            ? $response->json('data')
            : $this->getJson('/api/program-chair/areas')->json('data');

        $level = collect($payload['levels'] ?? [])->first(
            fn ($row) => (int) ($row['cycleId'] ?? 0) === (int) $this->cycle->id
        );

        return $level['areas'] ?? [];
    }

    public function test_program_chair_areas_returns_four_levels_and_seeds_ten_fixed_aaccup_areas(): void
    {
        $response = $this->getJson('/api/program-chair/areas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.programId', $this->program->id);

        $levels = collect($response->json('data.levels'));
        $this->assertEquals(['Level I', 'Level II', 'Level III', 'Level IV'], $levels->pluck('level')->all());

        $emptyLevels = $levels->whereNull('cycleId');
        $this->assertCount(3, $emptyLevels);
        $emptyLevels->each(function ($level) {
            $this->assertSame('Not Started', $level['displayStatus']);
            $this->assertSame([], $level['areas']);
        });

        $areas = $this->areasFromResponse($response);
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
        $this->assertSame(0, $levels->firstWhere('level', 'Level I')['assignedCount']);
    }

    public function test_program_chair_areas_is_idempotent(): void
    {
        $this->getJson('/api/program-chair/areas')->assertStatus(200);
        $again = $this->getJson('/api/program-chair/areas');

        $this->assertCount(10, $this->areasFromResponse($again));

        $seeded = AccreditationArea::where('cycle_id', $this->cycle->id)->whereNotNull('code')->count();
        $this->assertEquals(10, $seeded);
    }

    public function test_program_chair_areas_uses_latest_cycle_for_a_level(): void
    {
        $older = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'level' => 'Level I',
            'status' => 'Planning',
            'created_at' => now()->subDay(),
        ]);
        $this->cycle->update(['created_at' => now()]);

        $response = $this->getJson('/api/program-chair/areas');
        $levelI = collect($response->json('data.levels'))->firstWhere('level', 'Level I');

        $this->assertEquals($this->cycle->id, $levelI['cycleId']);
        $this->assertNotEquals($older->id, $levelI['cycleId']);
    }

    public function test_assign_chair_replaces_previous_and_removes_chair_from_members(): void
    {
        $areas = $this->areasFromResponse();
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
        $this->assertTrue($facultyA->fresh()->isAreaIncharge());

        // Re-assign without confirm is blocked.
        $facultyB = User::factory()->create();
        $facultyB->assignRole('Faculty');
        $this->postJson("/api/accreditation-areas/{$area->id}/assign-chair", ['chair_id' => $facultyB->id])
            ->assertStatus(409)
            ->assertJsonPath('data.requiresConfirmation', true);

        $this->postJson("/api/accreditation-areas/{$area->id}/assign-chair", [
            'chair_id' => $facultyB->id,
            'confirm_reassign' => true,
        ])->assertStatus(200)->assertJsonPath('success', true);

        $area->refresh();
        $this->assertEquals($facultyB->id, $area->chair_id);
    }

    public function test_assign_chair_notifies_new_assignee_but_not_on_same_chair_save(): void
    {
        Notification::fake();

        $areas = $this->areasFromResponse();
        $area = AccreditationArea::findOrFail($areas[0]['id']);
        $faculty = User::factory()->create();
        $faculty->assignRole('Faculty');

        $this->postJson("/api/accreditation-areas/{$area->id}/assign-chair", ['chair_id' => $faculty->id])
            ->assertStatus(200);

        Notification::assertSentTo($faculty, AreaInChargeAssignedNotification::class);
        $this->assertContains('mail', (new AreaInChargeAssignedNotification($area->fresh(['cycle.program'])))->via($faculty));

        Notification::fake();
        $this->postJson("/api/accreditation-areas/{$area->id}/assign-chair", ['chair_id' => $faculty->id])
            ->assertStatus(200);

        Notification::assertNothingSent();
    }

    public function test_set_members_excludes_chair_from_member_list(): void
    {
        $areas = $this->areasFromResponse();
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
        $areas = $this->areasFromResponse();
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
        $areas = $this->areasFromResponse();
        $area = AccreditationArea::findOrFail($areas[0]['id']);

        $this->postJson("/api/accreditation-areas/{$area->id}/set-members", ['user_ids' => []])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame(0, AreaMember::where('area_id', $area->id)->count());
    }

    public function test_set_members_updates_role_scope_for_assigned_members(): void
    {
        $areas = $this->areasFromResponse();
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

    public function test_non_program_chair_without_a_program_cannot_list_program_chair_areas(): void
    {
        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);

        $this->getJson('/api/program-chair/areas')->assertStatus(403);
    }

    public function test_faculty_sees_areas_locked_to_the_active_level(): void
    {
        $this->program->update(['active_cycle_id' => $this->cycle->id]);

        $faculty = User::factory()->create(['program_id' => $this->program->id]);
        $faculty->assignRole('Faculty');
        Sanctum::actingAs($faculty);

        $this->getJson('/api/program-chair/areas')
            ->assertStatus(200)
            ->assertJsonPath('data.lockedToActiveLevel', true)
            ->assertJsonPath('data.activeCycleId', $this->cycle->id)
            ->assertJsonPath('data.activeLevel', 'Level I');
    }
}