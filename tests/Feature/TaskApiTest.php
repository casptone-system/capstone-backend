<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AccreditationCycle $cycle;
    private AccreditationArea $area;
    private Task $task;

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
        ]);
        $this->task = Task::factory()->create([
            'area_id' => $this->area->id,
            'title' => 'Prepare documentation for Area I',
            'priority' => 'Medium',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_index_returns_paginated_tasks(): void
    {
        Task::factory(3)->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]);

        $response = $this->getJson('/api/tasks');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'areaId', 'title', 'description', 'priority', 'status', 'dueDate', 'createdBy', 'createdAt', 'updatedAt'],
                ],
            ]);
    }

    public function test_index_can_filter_by_area_id(): void
    {
        $otherProgram = Program::factory()->create();
        $otherCycle = AccreditationCycle::factory()->create(['program_id' => $otherProgram->id]);
        $otherArea = AccreditationArea::factory()->create(['cycle_id' => $otherCycle->id]);
        Task::factory(2)->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]);
        Task::factory(1)->create(['area_id' => $otherArea->id, 'created_by' => $this->user->id]);

        $response = $this->getJson('/api/tasks?area_id=' . $this->area->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_index_can_filter_by_status(): void
    {
        Task::factory(2)->create(['area_id' => $this->area->id, 'created_by' => $this->user->id, 'status' => 'In Progress']);
        Task::factory(1)->create(['area_id' => $this->area->id, 'created_by' => $this->user->id, 'status' => 'Not Started']);

        $response = $this->getJson('/api/tasks?area_id=' . $this->area->id . '&status=In Progress');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_can_filter_by_priority(): void
    {
        Task::factory(2)->create(['area_id' => $this->area->id, 'created_by' => $this->user->id, 'priority' => 'High']);
        Task::factory(1)->create(['area_id' => $this->area->id, 'created_by' => $this->user->id, 'priority' => 'Low']);

        $response = $this->getJson('/api/tasks?area_id=' . $this->area->id . '&priority=High');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_can_filter_by_assigned_to(): void
    {
        $assignedUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $task1 = Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]);
        $task2 = Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]);

        $task1->assignments()->create(['user_id' => $assignedUser->id, 'assigned_at' => now()]);
        $task2->assignments()->create(['user_id' => $otherUser->id, 'assigned_at' => now()]);

        $response = $this->getJson('/api/tasks?assigned_to=' . $assignedUser->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_can_search_by_title(): void
    {
        $this->task->update(['title' => 'Research Task']);
        Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id, 'title' => 'Faculty Task']);

        $response = $this->getJson('/api/tasks?search=Research');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_store_creates_task(): void
    {
        $response = $this->postJson('/api/tasks', [
            'area_id' => $this->area->id,
            'title' => 'Collect faculty credentials',
            'description' => 'Gather all faculty CVs and diplomas',
            'priority' => 'High',
            'status' => 'Not Started',
            'due_date' => '2026-08-30',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Collect faculty credentials')
            ->assertJsonPath('data.description', 'Gather all faculty CVs and diplomas')
            ->assertJsonPath('data.priority', 'High')
            ->assertJsonPath('data.status', 'Not Started')
            ->assertJsonPath('data.dueDate', '2026-08-30')
            ->assertJsonPath('data.createdBy', $this->user->id);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/tasks', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['area_id', 'title']);
    }

    public function test_store_validates_priority_enum(): void
    {
        $response = $this->postJson('/api/tasks', [
            'area_id' => $this->area->id,
            'title' => 'Test task',
            'priority' => 'Invalid Priority',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_store_validates_status_enum(): void
    {
        $response = $this->postJson('/api/tasks', [
            'area_id' => $this->area->id,
            'title' => 'Test task',
            'status' => 'Invalid Status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_show_returns_task_details(): void
    {
        $assignedUser = User::factory()->create();
        $this->task->assignments()->create([
            'user_id' => $assignedUser->id,
            'assigned_at' => now(),
        ]);

        $response = $this->getJson('/api/tasks/' . $this->task->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->task->id)
            ->assertJsonPath('data.title', 'Prepare documentation for Area I')
            ->assertJsonPath('data.createdBy', $this->user->id);
    }

    public function test_update_modifies_task(): void
    {
        $response = $this->patchJson('/api/tasks/' . $this->task->id, [
            'title' => 'Updated task title',
            'priority' => 'Critical',
            'status' => 'In Progress',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Updated task title')
            ->assertJsonPath('data.priority', 'Critical')
            ->assertJsonPath('data.status', 'In Progress');

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'title' => 'Updated task title',
            'priority' => 'Critical',
            'status' => 'In Progress',
        ]);
    }

    public function test_destroy_deletes_task(): void
    {
        $response = $this->deleteJson('/api/tasks/' . $this->task->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('tasks', ['id' => $this->task->id]);
    }

    public function test_assign_members(): void
    {
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $response = $this->postJson('/api/tasks/' . $this->task->id . '/assign-members', [
            'user_ids' => [$member1->id, $member2->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Members assigned successfully.');

        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $this->task->id,
            'user_id' => $member1->id,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $this->task->id,
            'user_id' => $member2->id,
        ]);
    }

    public function test_assign_members_validates_required_fields(): void
    {
        $response = $this->postJson('/api/tasks/' . $this->task->id . '/assign-members', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_ids']);
    }

    public function test_assign_members_validates_user_exists(): void
    {
        $response = $this->postJson('/api/tasks/' . $this->task->id . '/assign-members', [
            'user_ids' => [99999],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_ids.0']);
    }

    public function test_assign_members_does_not_duplicate_assignments(): void
    {
        $member = User::factory()->create();
        $this->task->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);

        $response = $this->postJson('/api/tasks/' . $this->task->id . '/assign-members', [
            'user_ids' => [$member->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('task_assignments', 1);
    }

    public function test_remove_assignment(): void
    {
        $member = User::factory()->create();
        $assignment = $this->task->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);

        $response = $this->deleteJson('/api/tasks/' . $this->task->id . '/assignments/' . $assignment->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Member unassigned successfully.');

        $this->assertDatabaseMissing('task_assignments', ['id' => $assignment->id]);
    }

    public function test_remove_assignment_returns_404_if_not_belonging_to_task(): void
    {
        $otherTask = Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]);
        $member = User::factory()->create();
        $assignment = $otherTask->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);

        $response = $this->deleteJson('/api/tasks/' . $this->task->id . '/assignments/' . $assignment->id);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_mark_complete(): void
    {
        $this->task->update(['status' => 'In Progress']);

        $response = $this->postJson('/api/tasks/' . $this->task->id . '/mark-complete');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Task marked as completed.')
            ->assertJsonPath('data.status', 'Completed');

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'status' => 'Completed',
        ]);
    }

    public function test_progress_returns_task_progress(): void
    {
        $member = User::factory()->create();
        $this->task->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);
        $this->task->update(['status' => 'In Progress']);

        $response = $this->getJson('/api/tasks/' . $this->task->id . '/progress');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.progress.status', 'In Progress')
            ->assertJsonPath('data.progress.totalAssignments', 1)
            ->assertJsonPath('data.progress.isOverdue', false);
    }

    public function test_progress_shows_overdue_when_past_due_date(): void
    {
        $this->task->update([
            'due_date' => now()->subDays(1),
            'status' => 'In Progress',
        ]);

        $response = $this->getJson('/api/tasks/' . $this->task->id . '/progress');

        $response->assertStatus(200)
            ->assertJsonPath('data.progress.isOverdue', true);
    }

    public function test_progress_shows_not_overdue_when_completed(): void
    {
        $this->task->update([
            'due_date' => now()->subDays(1),
            'status' => 'Completed',
        ]);

        $response = $this->getJson('/api/tasks/' . $this->task->id . '/progress');

        $response->assertStatus(200)
            ->assertJsonPath('data.progress.isOverdue', false);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        auth()->forgetGuards();

        $response = $this->getJson('/api/tasks');

        $response->assertStatus(401);
    }
}