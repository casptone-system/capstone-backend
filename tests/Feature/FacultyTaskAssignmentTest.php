<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationInstrument;
use App\Models\AccreditationRequirement;
use App\Models\Program;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FacultyTaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private $vpaa;
    private $dean;
    private $programChair;
    private $faculty;
    private $program;
    private $cycle;
    private $instrument;
    private $area;
    private $requirement;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users
        $this->vpaa = User::factory()->create(['role' => 'vpaa']);
        $this->dean = User::factory()->create(['role' => 'dean']);
        $this->programChair = User::factory()->create(['role' => 'program_chair']);
        $this->faculty = User::factory()->create(['role' => 'faculty']);

        // Create program with chair
        $this->program = Program::factory()->create(['chair_id' => $this->programChair->id]);

        // Assign faculty to program
        $this->program->members()->attach($this->faculty);

        // Create accreditation cycle
        $this->cycle = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'level' => 'Level III',
            'phase' => 'Preparation',
            'workflow_status' => 'Forwarded to Chair',
        ]);

        // Create instrument and structure
        $this->instrument = AccreditationInstrument::factory()->create([
            'name' => 'Teacher Education Instrument',
        ]);

        $this->cycle->update(['instrument_id' => $this->instrument->id]);

        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'instrument_id' => $this->instrument->id,
            'name' => 'Area II: Faculty Development',
        ]);

        $this->requirement = $this->area->requirements()->create([
            'code' => '2.1',
            'title' => 'Faculty Professional Development Records',
            'description' => 'Faculty must maintain records of professional development activities.',
            'evidence_guidance' => 'Submit certificates, attendance records, and summary of professional development.',
            'required_evidence_type' => 'Document',
            'status' => 'Not Started',
        ]);
    }

    public function test_program_chair_can_assign_faculty_to_requirement(): void
    {
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit required faculty development records.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Faculty assigned to requirement successfully.')
            ->assertJsonPath('data.status', 'Not Started');

        // Verify task created
        $this->assertDatabaseHas('tasks', [
            'accreditation_cycle_id' => $this->cycle->id,
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'requirement_id' => $this->requirement->id,
            'assigned_by' => $this->programChair->id,
        ]);

        // Verify assignment created
        $task = Task::where('requirement_id', $this->requirement->id)->first();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $this->faculty->id,
        ]);
    }

    public function test_unauthorized_user_cannot_assign_faculty(): void
    {
        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ])
            ->assertStatus(403);
    }

    public function test_program_chair_cannot_assign_faculty_from_different_program(): void
    {
        $otherProgram = Program::factory()->create();
        $otherFaculty = User::factory()->create(['role' => 'faculty']);
        $otherProgram->members()->attach($otherFaculty);

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $otherFaculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_faculty_can_see_assigned_task(): void
    {
        // Program Chair assigns task
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit faculty development records.',
            ]);

        // Faculty retrieves their tasks
        $this->actingAs($this->faculty, 'sanctum')
            ->getJson('/api/faculty/tasks')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_faculty_can_view_task_detail_with_full_context(): void
    {
        // Program Chair assigns task
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit faculty development records.',
            ]);

        $taskId = $response->json('data.id');

        // Faculty views task detail
        $this->actingAs($this->faculty, 'sanctum')
            ->getJson('/api/faculty/tasks/' . $taskId)
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Faculty Professional Development Records')
            ->assertJsonPath('data.instructions', 'Submit faculty development records.')
            ->assertJsonPath('data.deadline', '2026-09-15')
            ->assertJsonPath('data.status', 'Not Started')
            // Cycle info
            ->assertJsonPath('data.cycle.level', 'Level III')
            ->assertJsonPath('data.cycle.phase', 'Preparation')
            ->assertJsonPath('data.cycle.workflowStatus', 'Forwarded to Chair')
            // Requirement info
            ->assertJsonPath('data.requirement.code', '2.1')
            ->assertJsonPath('data.requirement.title', 'Faculty Professional Development Records')
            // Area info
            ->assertJsonPath('data.area.name', 'Area II: Faculty Development');
    }

    public function test_faculty_cannot_see_other_facultys_task(): void
    {
        $otherFaculty = User::factory()->create(['role' => 'faculty']);
        $this->program->members()->attach($otherFaculty);

        // Program Chair assigns task to first faculty
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        // Other faculty cannot access task
        $this->actingAs($otherFaculty, 'sanctum')
            ->getJson('/api/faculty/tasks/' . $taskId)
            ->assertStatus(403);
    }

    public function test_faculty_receives_notification_on_task_assignment(): void
    {
        // Program Chair assigns task
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit faculty development records.',
            ]);

        // Verify notification database record
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->faculty->id,
            'notifiable_type' => 'App\Models\User',
        ]);
    }

    public function test_task_links_requirement_to_faculty(): void
    {
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $task = Task::find($response->json('data.id'));

        // Task connects all relevant entities
        $this->assertEquals($this->requirement->id, $task->requirement_id);
        $this->assertEquals($this->area->id, $task->area_id);
        $this->assertEquals($this->cycle->id, $task->accreditation_cycle_id);
        $this->assertEquals($this->program->id, $task->program_id);
        $this->assertEquals($this->programChair->id, $task->assigned_by);
    }

    public function test_faculty_can_update_task_status_to_in_progress(): void
    {
        // Program Chair assigns task
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        // Faculty updates status to In Progress
        $this->actingAs($this->faculty, 'sanctum')
            ->patchJson('/api/faculty/tasks/' . $taskId, [
                'status' => 'In Progress',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'In Progress');
    }

    public function test_faculty_can_submit_task_with_evidence(): void
    {
        // Program Chair assigns task
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        // Faculty submits with evidence
        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/submit', [
                'status' => 'Submitted',
                'submitted_notes' => 'Attached faculty development certificates.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('success', true);
    }

    public function test_program_chair_can_view_submitted_tasks_pending_review(): void
    {
        // Program Chair assigns task
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        // Faculty submits
        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/submit', [
                'status' => 'Submitted',
            ]);

        // Program Chair sees pending reviews
        $this->actingAs($this->programChair, 'sanctum')
            ->getJson('/api/program-chair/tasks-pending-review')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_program_chair_can_approve_submitted_task(): void
    {
        // Program Chair assigns and faculty submits
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/submit', [
                'status' => 'Submitted',
            ]);

        // Program Chair approves
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/approve', [
                'status' => 'Approved',
                'reviewer_notes' => 'Excellent documentation.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'Approved');
    }

    public function test_program_chair_can_return_task_for_revision(): void
    {
        // Program Chair assigns and faculty submits
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/submit', [
                'status' => 'Submitted',
            ]);

        // Program Chair returns for revision
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/return', [
                'status' => 'Returned',
                'return_reason' => 'Please include the training dates for all certificates.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'Returned')
            ->assertJsonPath('data.returnReason', 'Please include the training dates for all certificates.');
    }

    public function test_faculty_can_see_return_reason_and_resubmit(): void
    {
        // Program Chair assigns, faculty submits, and returns
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/submit', [
                'status' => 'Submitted',
            ]);

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/return', [
                'status' => 'Returned',
                'return_reason' => 'Add training dates.',
            ]);

        // Faculty views task and sees return reason
        $this->actingAs($this->faculty, 'sanctum')
            ->getJson('/api/faculty/tasks/' . $taskId)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'Returned')
            ->assertJsonPath('data.returnReason', 'Add training dates.');

        // Faculty resubmits
        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/submit', [
                'status' => 'Resubmitted',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'Resubmitted');
    }

    public function test_faculty_notification_includes_task_details(): void
    {
        Notification::fake();

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit faculty development records.',
            ]);

        Notification::assertSentTo(
            $this->faculty,
            \App\Notifications\TaskAssignedNotification::class
        );
    }

    public function test_requirement_status_updates_when_task_approved(): void
    {
        // Program Chair assigns task
        $response = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-requirements/' . $this->requirement->id . '/assign-faculty', [
                'faculty_id' => $this->faculty->id,
                'deadline' => '2026-09-15',
                'instructions' => 'Submit records.',
            ]);

        $taskId = $response->json('data.id');

        // Faculty submits
        $this->actingAs($this->faculty, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/submit', [
                'status' => 'Submitted',
            ]);

        // Program Chair approves
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/faculty/tasks/' . $taskId . '/approve', [
                'status' => 'Approved',
            ]);

        // Verify requirement status updated
        $this->requirement->refresh();
        $this->assertEquals('Completed', $this->requirement->status);
    }
}
