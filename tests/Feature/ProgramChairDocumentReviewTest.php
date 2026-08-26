<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Document;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProgramChairDocumentReviewTest extends TestCase
{
    private User $programChair;
    private User $areaChair;
    private User $dean;
    private Program $program;
    private AccreditationArea $area;
    private ParameterContentRow $row;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Role::firstOrCreate(['name' => 'program-chair', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Area In-Charge', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $this->program = Program::factory()->create(['college_id' => $college->id]);

        $this->programChair = User::factory()->create(['program_id' => $this->program->id]);
        $this->programChair->assignRole('program-chair');
        $this->program->update(['chair_id' => $this->programChair->id]);

        $this->areaChair = User::factory()->create(['program_id' => $this->program->id]);
        $this->areaChair->assignRole('Area In-Charge');

        $this->dean = User::factory()->create(['college_id' => $college->id]);
        $this->dean->assignRole('Dean');

        $cycle = AccreditationCycle::factory()->create(['program_id' => $this->program->id]);
        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'chair_id' => $this->areaChair->id,
        ]);

        $parameter = AccreditationParameter::create([
            'area_id' => $this->area->id,
            'code' => 'A',
            'name' => 'Test parameter',
            'sort_order' => 1,
        ]);
        $this->row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'Need a PDF',
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($this->areaChair);
        $this->document = Document::query()->findOrFail(
            $this->postJson('/api/documents', [
                'program_id' => $this->program->id,
                'content_row_id' => $this->row->id,
                'title' => 'Evidence',
                'file' => UploadedFile::fake()->create('evidence.pdf', 40, 'application/pdf'),
            ])->assertStatus(201)->json('data.id')
        );
    }

    public function test_program_chair_can_approve_and_return_a_document(): void
    {
        Sanctum::actingAs($this->programChair);

        $this->postJson("/api/documents/{$this->document->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'Approved');

        $this->assertDatabaseHas('documents', [
            'id' => $this->document->id,
            'status' => 'Approved',
        ]);
        $this->assertTrue(
            AuditLog::query()->where('event', 'review_approved')->where('path', 'like', '%documents/'.$this->document->id.'/approve%')->exists()
        );

        $this->postJson("/api/documents/{$this->document->id}/request-revision", [
            'comment' => 'Returned for revision by Program Chair.',
        ])->assertStatus(200)->assertJsonPath('data.status', 'Revision Requested');

        $this->assertDatabaseHas('documents', [
            'id' => $this->document->id,
            'status' => 'Revision Requested',
        ]);
        $this->assertTrue(
            AuditLog::query()->where('event', 'review_revision_requested')->exists()
        );
    }

    public function test_dean_and_area_chair_cannot_approve_documents(): void
    {
        Sanctum::actingAs($this->areaChair);
        $this->postJson("/api/documents/{$this->document->id}/approve")->assertStatus(403);

        Sanctum::actingAs($this->dean);
        $this->postJson("/api/documents/{$this->document->id}/approve")->assertStatus(403);
        $this->postJson("/api/documents/{$this->document->id}/request-revision", [
            'comment' => 'Dean return',
        ])->assertStatus(403);
    }

    public function test_done_uploaded_row_moves_from_pending_to_completed_on_program_chair_approve(): void
    {
        Sanctum::actingAs($this->areaChair);
        $this->patchJson("/api/parameter-rows/{$this->row->id}/status", ['is_done' => true])->assertStatus(200);

        $stats = $this->getJson('/api/users/me/areas')->assertStatus(200)->json('meta.taskStats');
        $this->assertSame(0, $stats['completed']);
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(1, $stats['pendingReviews']);
        $this->area->refresh();
        $this->assertSame(0, $this->area->progress_percent);

        Sanctum::actingAs($this->programChair);
        $this->postJson("/api/documents/{$this->document->id}/approve")->assertStatus(200);

        Sanctum::actingAs($this->areaChair);
        $stats = $this->getJson('/api/users/me/areas')->assertStatus(200)->json('meta.taskStats');
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(0, $stats['pending']);
        $this->assertSame(0, $stats['pendingReviews']);
        $this->assertSame(100, $stats['progressPercent']);
        $this->area->refresh();
        $this->assertSame(100, $this->area->progress_percent);
    }

    public function test_program_chair_area_review_approve_skips_dean_and_goes_to_ready(): void
    {
        $review = Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->area->cycle_id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->areaChair->id,
        ]);

        Sanctum::actingAs($this->dean);
        $this->postJson("/api/reviews/{$review->id}/approve")->assertStatus(403);

        Sanctum::actingAs($this->programChair);
        $this->postJson("/api/reviews/{$review->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.currentStatus', 'Ready');
    }
}
