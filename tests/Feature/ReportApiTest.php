<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private College $college;
    private Program $program;
    private AccreditationCycle $cycle;
    private AccreditationArea $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->college = College::factory()->create([
            'name' => 'College of Computer Studies',
            'code' => 'CCS',
        ]);

        $this->program = Program::factory()->create([
            'college_id' => $this->college->id,
            'name' => 'BS Computer Science',
            'code' => 'BSCS',
        ]);

        $this->cycle = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'level' => 'Level I',
            'status' => 'Preparation',
        ]);

        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area I: Vision, Mission, Goals',
            'status' => 'In Progress',
            'chair_id' => $this->user->id,
        ]);
    }

    // --- Report Index ---

    public function test_report_index_lists_all_report_types(): void
    {
        $response = $this->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['type', 'name', 'description', 'endpoint', 'formats'],
                ],
            ]);

        $response->assertJsonCount(5, 'data');
    }

    // --- Compliance Report ---

    public function test_compliance_report_returns_json(): void
    {
        Document::factory(2)->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reports/compliance');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reportType', 'Compliance Report')
            ->assertJsonStructure([
                'data' => [
                    'reportType',
                    'generatedAt',
                    'filters',
                    'summary' => [
                        'totalAreas',
                        'areasWithEvidence',
                        'areasCompleted',
                        'areasInProgress',
                        'areasNotStarted',
                        'totalDocuments',
                        'activeDocuments',
                        'archivedDocuments',
                        'totalTasks',
                        'completedTasks',
                        'overdueTasks',
                        'totalReviews',
                        'pendingReviews',
                        'approvedReviews',
                        'rejectedReviews',
                        'compliancePercent',
                    ],
                    'areas',
                ],
            ]);
    }

    public function test_compliance_report_calculates_metrics_correctly(): void
    {
        // Create a second area without evidence
        AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area II: Faculty',
            'status' => 'Not Started',
        ]);

        // Add documents to area 1
        Document::factory(3)->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        // Add an overdue task
        Task::factory()->create([
            'area_id' => $this->area->id,
            'due_date' => now()->subDays(1),
            'status' => 'In Progress',
            'created_by' => $this->user->id,
        ]);

        // Add a pending review
        Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reports/compliance');

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.totalAreas', 2)
            ->assertJsonPath('data.summary.areasWithEvidence', 1)
            ->assertJsonPath('data.summary.areasInProgress', 1)
            ->assertJsonPath('data.summary.areasNotStarted', 1)
            ->assertJsonPath('data.summary.totalDocuments', 3)
            ->assertJsonPath('data.summary.overdueTasks', 1)
            ->assertJsonPath('data.summary.pendingReviews', 1)
            ->assertJsonPath('data.summary.compliancePercent', 50);
    }

    public function test_compliance_report_supports_program_filter(): void
    {
        $program2 = Program::factory()->create([
            'college_id' => $this->college->id,
            'name' => 'BS Information Technology',
        ]);
        $cycle2 = AccreditationCycle::factory()->create([
            'program_id' => $program2->id,
        ]);
        AccreditationArea::factory()->create([
            'cycle_id' => $cycle2->id,
            'name' => 'Area II: Faculty',
        ]);

        $response = $this->getJson('/api/reports/compliance?program_id=' . $this->program->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.totalAreas', 1)
            ->assertJsonPath('data.filters.programId', $this->program->id);
    }

    public function test_compliance_report_returns_zero_when_no_data(): void
    {
        Task::query()->delete();
        Review::query()->delete();
        Document::query()->delete();
        AccreditationArea::query()->delete();
        AccreditationCycle::query()->delete();
        Program::query()->delete();
        College::query()->delete();

        $response = $this->getJson('/api/reports/compliance');

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.totalAreas', 0)
            ->assertJsonPath('data.summary.totalDocuments', 0)
            ->assertJsonPath('data.summary.compliancePercent', 0);
    }

    public function test_compliance_report_pdf_export(): void
    {
        Document::factory()->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reports/compliance?format=pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_compliance_report_excel_export(): void
    {
        Document::factory()->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reports/compliance?format=excel');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/vnd.ms-excel', $response->headers->get('Content-Type'));
        $content = $response->getContent();
        $this->assertStringContainsString('<table', $content);
        $this->assertStringContainsString('Compliance Report', $content);
    }

    // --- Program Report ---

    public function test_program_report_returns_json(): void
    {
        Document::factory()->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reports/programs/' . $this->program->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reportType', 'Program Report')
            ->assertJsonPath('data.program.name', 'BS Computer Science')
            ->assertJsonPath('data.program.code', 'BSCS')
            ->assertJsonPath('data.program.collegeName', 'College of Computer Studies')
            ->assertJsonStructure([
                'data' => [
                    'reportType',
                    'generatedAt',
                    'program' => ['id', 'name', 'code', 'chair', 'accreditationStatus', 'complianceScore', 'collegeName', 'collegeCode'],
                    'summary' => ['totalCycles', 'totalAreas', 'totalDocuments', 'totalTasks', 'overdueTasks', 'totalReviews', 'pendingReviews', 'areasWithEvidence', 'compliancePercent'],
                    'cycles',
                ],
            ]);
    }

    public function test_program_report_includes_cycles_and_areas(): void
    {
        $response = $this->getJson('/api/reports/programs/' . $this->program->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.totalCycles', 1)
            ->assertJsonPath('data.summary.totalAreas', 1)
            ->assertJsonCount(1, 'data.cycles')
            ->assertJsonPath('data.cycles.0.level', 'Level I')
            ->assertJsonPath('data.cycles.0.areas.0.areaName', 'Area I: Vision, Mission, Goals');
    }

    public function test_program_report_pdf_export(): void
    {
        $response = $this->getJson('/api/reports/programs/' . $this->program->id . '?format=pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_program_report_excel_export(): void
    {
        $response = $this->getJson('/api/reports/programs/' . $this->program->id . '?format=excel');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Program Report', $content);
        $this->assertStringContainsString('BS Computer Science', $content);
    }

    public function test_program_report_404_for_nonexistent_program(): void
    {
        $response = $this->getJson('/api/reports/programs/99999');

        $response->assertStatus(404);
    }

    // --- College Report ---

    public function test_college_report_returns_json(): void
    {
        $program2 = Program::factory()->create([
            'college_id' => $this->college->id,
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
        ]);
        $cycle2 = AccreditationCycle::factory()->create([
            'program_id' => $program2->id,
        ]);
        AccreditationArea::factory()->create([
            'cycle_id' => $cycle2->id,
        ]);

        $response = $this->getJson('/api/reports/colleges/' . $this->college->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reportType', 'College Report')
            ->assertJsonPath('data.college.name', 'College of Computer Studies')
            ->assertJsonPath('data.college.code', 'CCS')
            ->assertJsonStructure([
                'data' => [
                    'reportType',
                    'generatedAt',
                    'college' => ['id', 'name', 'code', 'description'],
                    'summary' => ['totalPrograms', 'totalCycles', 'totalAreas', 'totalDocuments', 'totalTasks', 'overdueTasks', 'totalReviews', 'pendingReviews', 'areasWithEvidence', 'compliancePercent'],
                    'programs',
                ],
            ]);

        $response->assertJsonPath('data.summary.totalPrograms', 2)
            ->assertJsonCount(2, 'data.programs');
    }

    public function test_college_report_pdf_export(): void
    {
        $response = $this->getJson('/api/reports/colleges/' . $this->college->id . '?format=pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_college_report_excel_export(): void
    {
        $response = $this->getJson('/api/reports/colleges/' . $this->college->id . '?format=excel');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('College Report', $content);
        $this->assertStringContainsString('College of Computer Studies', $content);
    }

    public function test_college_report_404_for_nonexistent_college(): void
    {
        $response = $this->getJson('/api/reports/colleges/99999');

        $response->assertStatus(404);
    }

    // --- Area Report ---

    public function test_area_report_returns_json(): void
    {
        Document::factory(2)->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        Task::factory()->create([
            'area_id' => $this->area->id,
            'status' => 'In Progress',
            'created_by' => $this->user->id,
        ]);

        AreaMember::factory()->create([
            'area_id' => $this->area->id,
            'user_id' => $this->user->id,
            'role' => 'Member',
        ]);

        Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reports/areas/' . $this->area->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reportType', 'Area Report')
            ->assertJsonPath('data.area.name', 'Area I: Vision, Mission, Goals')
            ->assertJsonPath('data.area.programName', 'BS Computer Science')
            ->assertJsonPath('data.area.collegeName', 'College of Computer Studies')
            ->assertJsonStructure([
                'data' => [
                    'reportType',
                    'generatedAt',
                    'area',
                    'summary',
                    'members',
                    'documents',
                    'tasks',
                    'reviews',
                ],
            ]);

        $response->assertJsonPath('data.summary.totalDocuments', 2)
            ->assertJsonPath('data.summary.totalTasks', 1)
            ->assertJsonPath('data.summary.totalMembers', 1)
            ->assertJsonPath('data.summary.totalReviews', 1)
            ->assertJsonPath('data.summary.pendingReviews', 1);
    }

    public function test_area_report_pdf_export(): void
    {
        $response = $this->getJson('/api/reports/areas/' . $this->area->id . '?format=pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_area_report_excel_export(): void
    {
        $response = $this->getJson('/api/reports/areas/' . $this->area->id . '?format=excel');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Area Report', $content);
        $this->assertStringContainsString('Area I: Vision, Mission, Goals', $content);
    }

    public function test_area_report_404_for_nonexistent_area(): void
    {
        $response = $this->getJson('/api/reports/areas/99999');

        $response->assertStatus(404);
    }

    // --- Accreditation Report ---

    public function test_accreditation_report_returns_json(): void
    {
        AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area II: Faculty',
            'status' => 'Completed',
        ]);

        Document::factory()->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);

        Task::factory()->create([
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
        ]);

        Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reports/accreditation-cycles/' . $this->cycle->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reportType', 'Accreditation Report')
            ->assertJsonPath('data.cycle.level', 'Level I')
            ->assertJsonPath('data.cycle.status', 'Preparation')
            ->assertJsonPath('data.cycle.programName', 'BS Computer Science')
            ->assertJsonPath('data.cycle.collegeName', 'College of Computer Studies')
            ->assertJsonStructure([
                'data' => [
                    'reportType',
                    'generatedAt',
                    'cycle',
                    'summary' => ['totalAreas', 'areasCompleted', 'areasInProgress', 'areasNotStarted', 'areasWithEvidence', 'totalDocuments', 'totalTasks', 'overdueTasks', 'totalReviews', 'pendingReviews', 'compliancePercent', 'readinessPercent'],
                    'areas',
                ],
            ]);

        $response->assertJsonPath('data.summary.totalAreas', 2)
            ->assertJsonPath('data.summary.areasCompleted', 1)
            ->assertJsonPath('data.summary.areasInProgress', 1)
            ->assertJsonPath('data.summary.areasWithEvidence', 1)
            ->assertJsonPath('data.summary.totalDocuments', 1)
            ->assertJsonPath('data.summary.totalTasks', 1)
            ->assertJsonPath('data.summary.totalReviews', 1)
            ->assertJsonPath('data.summary.compliancePercent', 50)
            ->assertJsonPath('data.summary.readinessPercent', 50);
    }

    public function test_accreditation_report_pdf_export(): void
    {
        $response = $this->getJson('/api/reports/accreditation-cycles/' . $this->cycle->id . '?format=pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_accreditation_report_excel_export(): void
    {
        $response = $this->getJson('/api/reports/accreditation-cycles/' . $this->cycle->id . '?format=excel');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Accreditation Report', $content);
        $this->assertStringContainsString('Level I', $content);
    }

    public function test_accreditation_report_404_for_nonexistent_cycle(): void
    {
        $response = $this->getJson('/api/reports/accreditation-cycles/99999');

        $response->assertStatus(404);
    }

    // --- Authentication ---

    public function test_unauthenticated_access_is_rejected(): void
    {
        auth()->forgetGuards();

        $response = $this->getJson('/api/reports');
        $response->assertStatus(401);

        $response = $this->getJson('/api/reports/compliance');
        $response->assertStatus(401);

        $response = $this->getJson('/api/reports/programs/' . $this->program->id);
        $response->assertStatus(401);
    }
}