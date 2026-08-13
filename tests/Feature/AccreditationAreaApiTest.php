<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AreaMember;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccreditationAreaApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AccreditationCycle $cycle;
    private AccreditationArea $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $program = Program::factory()->create();
        $this->cycle = AccreditationCycle::factory()->create(['program_id' => $program->id]);
        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area I: Vision, Mission, Goals',
            // ensure deterministic status for tests that filter by status
            'status' => 'Not Started',
        ]);
    }

    public function test_index_returns_paginated_accreditation_areas(): void
    {
        AccreditationArea::factory(3)->create(['cycle_id' => $this->cycle->id]);

        $response = $this->getJson('/api/accreditation-areas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'cycleId', 'name', 'description', 'chairId', 'status', 'createdAt', 'updatedAt'],
                ],
            ]);
    }

    public function test_index_can_filter_by_cycle_id(): void
    {
        $otherProgram = Program::factory()->create();
        $otherCycle = AccreditationCycle::factory()->create(['program_id' => $otherProgram->id]);
        AccreditationArea::factory(2)->create(['cycle_id' => $this->cycle->id]);
        AccreditationArea::factory(1)->create(['cycle_id' => $otherCycle->id]);

        $response = $this->getJson('/api/accreditation-areas?cycle_id=' . $this->cycle->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_index_can_filter_by_status(): void
    {
        AccreditationArea::factory(2)->create(['cycle_id' => $this->cycle->id, 'status' => 'In Progress']);
        AccreditationArea::factory(1)->create(['cycle_id' => $this->cycle->id, 'status' => 'Not Started']);

        $response = $this->getJson('/api/accreditation-areas?cycle_id=' . $this->cycle->id . '&status=In Progress');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_can_search_by_name(): void
    {
        $this->area->update(['name' => 'Research Area']);
        AccreditationArea::factory()->create(['cycle_id' => $this->cycle->id, 'name' => 'Faculty Area']);

        $response = $this->getJson('/api/accreditation-areas?search=Research');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_store_creates_accreditation_area(): void
    {
        $response = $this->postJson('/api/accreditation-areas', [
            'cycle_id' => $this->cycle->id,
            'name' => 'Area V: Research',
            'description' => 'Research area description',
            'status' => 'Not Started',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Area V: Research')
            ->assertJsonPath('data.description', 'Research area description')
            ->assertJsonPath('data.status', 'Not Started');
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/accreditation-areas', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cycle_id', 'name']);
    }

    public function test_store_validates_status_enum(): void
    {
        $response = $this->postJson('/api/accreditation-areas', [
            'cycle_id' => $this->cycle->id,
            'name' => 'Area V: Research',
            'status' => 'Invalid Status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_show_returns_accreditation_area_details(): void
    {
        $chair = User::factory()->create();
        $this->area->update(['chair_id' => $chair->id]);

        $member = $this->area->members()->create([
            'user_id' => User::factory()->create()->id,
            'role' => 'member',
        ]);

        $response = $this->getJson('/api/accreditation-areas/' . $this->area->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->area->id)
            ->assertJsonPath('data.name', 'Area I: Vision, Mission, Goals')
            ->assertJsonPath('data.chairId', $chair->id);
    }

    public function test_update_modifies_accreditation_area(): void
    {
        $response = $this->patchJson('/api/accreditation-areas/' . $this->area->id, [
            'name' => 'Area I: Updated Vision',
            'status' => 'In Progress',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Area I: Updated Vision')
            ->assertJsonPath('data.status', 'In Progress');

        $this->assertDatabaseHas('accreditation_areas', [
            'id' => $this->area->id,
            'name' => 'Area I: Updated Vision',
            'status' => 'In Progress',
        ]);
    }

    public function test_destroy_deletes_accreditation_area(): void
    {
        $response = $this->deleteJson('/api/accreditation-areas/' . $this->area->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('accreditation_areas', ['id' => $this->area->id]);
    }

    public function test_assign_chair(): void
    {
        $chair = User::factory()->create();

        $response = $this->postJson('/api/accreditation-areas/' . $this->area->id . '/assign-chair', [
            'chair_id' => $chair->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Chair assigned successfully.')
            ->assertJsonPath('data.chairId', $chair->id);

        $this->assertDatabaseHas('accreditation_areas', [
            'id' => $this->area->id,
            'chair_id' => $chair->id,
        ]);
    }

    public function test_assign_chair_validates_required_fields(): void
    {
        $response = $this->postJson('/api/accreditation-areas/' . $this->area->id . '/assign-chair', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['chair_id']);
    }

    public function test_assign_chair_validates_user_exists(): void
    {
        $response = $this->postJson('/api/accreditation-areas/' . $this->area->id . '/assign-chair', [
            'chair_id' => 99999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['chair_id']);
    }

    public function test_add_member(): void
    {
        $memberUser = User::factory()->create();

        $response = $this->postJson('/api/accreditation-areas/' . $this->area->id . '/members', [
            'user_id' => $memberUser->id,
            'role' => 'secretary',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Member added successfully.')
            ->assertJsonPath('data.userId', $memberUser->id)
            ->assertJsonPath('data.role', 'secretary');

        $this->assertDatabaseHas('area_members', [
            'area_id' => $this->area->id,
            'user_id' => $memberUser->id,
            'role' => 'secretary',
        ]);
    }

    public function test_add_member_defaults_to_member_role(): void
    {
        $memberUser = User::factory()->create();

        $response = $this->postJson('/api/accreditation-areas/' . $this->area->id . '/members', [
            'user_id' => $memberUser->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.role', 'member');

        $this->assertDatabaseHas('area_members', [
            'area_id' => $this->area->id,
            'user_id' => $memberUser->id,
            'role' => 'member',
        ]);
    }

    public function test_add_member_validates_required_fields(): void
    {
        $response = $this->postJson('/api/accreditation-areas/' . $this->area->id . '/members', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_add_member_validates_user_exists(): void
    {
        $response = $this->postJson('/api/accreditation-areas/' . $this->area->id . '/members', [
            'user_id' => 99999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_remove_member(): void
    {
        $memberUser = User::factory()->create();
        $member = $this->area->members()->create([
            'user_id' => $memberUser->id,
            'role' => 'member',
        ]);

        $response = $this->deleteJson('/api/accreditation-areas/' . $this->area->id . '/members/' . $member->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Member removed successfully.');

        $this->assertDatabaseMissing('area_members', ['id' => $member->id]);
    }

    public function test_remove_member_returns_404_if_member_not_in_area(): void
    {
        $otherArea = AccreditationArea::factory()->create(['cycle_id' => $this->cycle->id]);
        $memberUser = User::factory()->create();
        $member = $otherArea->members()->create([
            'user_id' => $memberUser->id,
            'role' => 'member',
        ]);

        $response = $this->deleteJson('/api/accreditation-areas/' . $this->area->id . '/members/' . $member->id);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_progress_returns_area_progress(): void
    {
        $chair = User::factory()->create();
        $this->area->update(['chair_id' => $chair->id, 'status' => 'In Progress']);

        $this->area->members()->create([
            'user_id' => User::factory()->create()->id,
            'role' => 'member',
        ]);

        $response = $this->getJson('/api/accreditation-areas/' . $this->area->id . '/progress');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.progress.status', 'In Progress')
            ->assertJsonPath('data.progress.totalMembers', 1)
            ->assertJsonPath('data.progress.hasChair', true);
    }

    public function test_progress_shows_no_chair_when_not_assigned(): void
    {
        $response = $this->getJson('/api/accreditation-areas/' . $this->area->id . '/progress');

        $response->assertStatus(200)
            ->assertJsonPath('data.progress.hasChair', false);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        auth()->forgetGuards();

        $response = $this->getJson('/api/accreditation-areas');

        $response->assertStatus(401);
    }
}