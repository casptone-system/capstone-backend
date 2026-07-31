<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Program $program;
    private AccreditationCycle $cycle;
    private AccreditationArea $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->program = Program::factory()->create(['name' => 'BS Computer Science']);
        $this->cycle = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'status' => 'Preparation',
        ]);
        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area I: Vision, Mission, Goals',
        ]);
    }

    public function test_dashboard_returns_all_metrics(): void
    {
        // Create a second program with its own cycle and area
        $program2 = Program::factory()->create(['name' => 'BS Information Technology']);
        $cycle2 = AccreditationCycle::factory()->create([
            'program_id' => $program2->id,
            'status' => 'Ready',
        ]);
        $area2 = AccreditationArea::factory()->create([
            'cycle_id' => $cycle2->id,
            'name' => 'Area II: Faculty',
        ]);

        // Add documents to area 1 (evidence)
        Document::factory(2)->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        // Add an overdue task
        Task::factory()->create([
            'area_id' => $this->area->id,
            'title' => 'Overdue task',
            'due_date' => now()->subDays(1),
            'status' => 'In Progress',
            'created_by' => $this->user->id,
        ]);

        // Add a completed task (should NOT count as overdue)
        Task::factory()->create([
            'area_id' => $this->area->id,
            'title' => 'Completed task',
            'due_date' => now()->subDays(5),
            'status' => 'Completed',
            'created_by' => $this->user->id,
        ]);

        // Add a pending review
        Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->user->id,
        ]);

        // Add a completed review (should NOT count as pending)
        Review::factory()->create([
            'area_id' => $area2->id,
            'cycle_id' => $cycle2->id,
            'current_status' => 'Ready',
            'submitted_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'totalPrograms',
                        'totalAreas',
                        'totalEvidence',
                        'totalCycles',
                        'compliancePercent',
                        'readinessPercent',
                        'pendingReviews',
                        'overdueTasks',
                        'areasWithEvidence',
                    ],
                    'breakdowns' => [
                        'areaStatuses',
                        'cycleStatuses',
                        'taskStatuses',
                        'reviewStatuses',
                    ],
                    'programs',
                ],
            ]);

        // Verify summary metrics
        $response->assertJsonPath('data.summary.totalPrograms', 2)
            ->assertJsonPath('data.summary.totalAreas', 2)
            ->assertJsonPath('data.summary.totalEvidence', 2)
            ->assertJsonPath('data.summary.totalCycles', 2)
            ->assertJsonPath('data.summary.pendingReviews', 1)
            ->assertJsonPath('data.summary.overdueTasks', 1)
            ->assertJsonPath('data.summary.areasWithEvidence', 1);

        // Readiness: 1 out of 2 cycles is Ready/Completed
        $response->assertJsonPath('data.summary.readinessPercent', 50);

        // Compliance: 1 out of 2 areas has evidence
        $response->assertJsonPath('data.summary.compliancePercent', 50);

        // Verify program breakdown exists
        $response->assertJsonCount(2, 'data.programs');
    }

    public function test_dashboard_can_filter_by_program_id(): void
    {
        $program2 = Program::factory()->create(['name' => 'BS Information Technology']);
        $cycle2 = AccreditationCycle::factory()->create([
            'program_id' => $program2->id,
            'status' => 'Ready',
        ]);
        AccreditationArea::factory()->create([
            'cycle_id' => $cycle2->id,
            'name' => 'Area II: Faculty',
        ]);

        // Add overdue task to program 2
        Task::factory()->create([
            'area_id' => $this->area->id,
            'title' => 'Overdue task',
            'due_date' => now()->subDays(1),
            'status' => 'In Progress',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/dashboard?program_id=' . $this->program->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.totalPrograms', 1)
            ->assertJsonPath('data.summary.totalAreas', 1)
            ->assertJsonPath('data.summary.overdueTasks', 1);

        // Programs breakdown should be null when filtered
        $response->assertJsonPath('data.programs', null);
    }

    public function test_dashboard_returns_zero_metrics_when_no_data(): void
    {
        // Clear all data
        Task::query()->delete();
        Review::query()->delete();
        Document::query()->delete();
        AccreditationArea::query()->delete();
        AccreditationCycle::query()->delete();
        Program::query()->delete();

        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.totalPrograms', 0)
            ->assertJsonPath('data.summary.totalAreas', 0)
            ->assertJsonPath('data.summary.totalEvidence', 0)
            ->assertJsonPath('data.summary.totalCycles', 0)
            ->assertJsonPath('data.summary.compliancePercent', 0)
            ->assertJsonPath('data.summary.readinessPercent', 0)
            ->assertJsonPath('data.summary.pendingReviews', 0)
            ->assertJsonPath('data.summary.overdueTasks', 0)
            ->assertJsonPath('data.summary.areasWithEvidence', 0);
    }

    public function test_dashboard_breakdowns_are_accurate(): void
    {
        // Area with status 'In Progress'
        AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area II: Faculty',
            'status' => 'In Progress',
        ]);

        // Overdue task
        Task::factory()->create([
            'area_id' => $this->area->id,
            'title' => 'Overdue task',
            'due_date' => now()->subDays(1),
            'status' => 'In Progress',
            'created_by' => $this->user->id,
        ]);

        // Submitted review
        Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(200);

        // Verify breakdowns are present
        $response->assertJsonStructure([
            'data' => [
                'breakdowns' => [
                    'areaStatuses',
                    'cycleStatuses',
                    'taskStatuses',
                    'reviewStatuses',
                ],
            ],
        ]);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        auth()->forgetGuards();

        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(401);
    }
}