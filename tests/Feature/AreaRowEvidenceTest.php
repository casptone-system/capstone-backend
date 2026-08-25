<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Document;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AreaRowEvidenceTest extends TestCase
{
    private User $chair;
    private User $member;
    private User $qa;
    private Program $program;
    private AccreditationArea $area;
    private AccreditationParameter $parameter;
    private ParameterContentRow $row;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Area In-Charge', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'QA', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'VPAA', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $this->program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create(['program_id' => $this->program->id]);

        $this->chair = User::factory()->create(['program_id' => $this->program->id]);
        $this->chair->assignRole('Area In-Charge');

        $this->member = User::factory()->create(['program_id' => $this->program->id]);
        $this->member->assignRole('Faculty');

        $this->qa = User::factory()->create();
        $this->qa->assignRole('QA');

        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'chair_id' => $this->chair->id,
        ]);

        AreaMember::create([
            'area_id' => $this->area->id,
            'user_id' => $this->member->id,
            'role' => 'member',
        ]);

        $this->parameter = AccreditationParameter::create([
            'area_id' => $this->area->id,
            'code' => 'A',
            'name' => 'Test parameter',
            'sort_order' => 1,
        ]);

        $this->row = ParameterContentRow::create([
            'parameter_id' => $this->parameter->id,
            'content' => 'Upload this statement',
            'sort_order' => 1,
        ]);
    }

    public function test_area_chair_can_upload_a_pdf_to_a_content_row(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Row evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertStatus(201)
            ->assertJsonPath('data.contentRowId', $this->row->id)
            ->assertJsonPath('data.areaId', $this->area->id);

        $this->area->refresh();
        $this->assertSame(100, $this->area->progress_percent);
    }

    public function test_area_member_cannot_upload_edit_remove_or_submit(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->member);

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Member upload',
            'file' => UploadedFile::fake()->create('member.pdf', 100, 'application/pdf'),
        ])->assertStatus(403);

        $this->deleteJson("/api/parameter-rows/{$this->row->id}/documents")->assertStatus(403);
        $this->postJson("/api/accreditation-areas/{$this->area->id}/submit-review")->assertStatus(403);
    }

    public function test_non_pdf_and_oversized_area_uploads_are_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Word file',
            'file' => UploadedFile::fake()->create('notes.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])->assertStatus(422);

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Huge pdf',
            'file' => UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_row_rejects_more_than_five_pdfs(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/documents', [
                'program_id' => $this->program->id,
                'content_row_id' => $this->row->id,
                'title' => "File {$i}",
                'file' => UploadedFile::fake()->create("file-{$i}.pdf", 20, 'application/pdf'),
            ])->assertStatus(201);
        }

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'File 6',
            'file' => UploadedFile::fake()->create('file-6.pdf', 20, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_progress_is_based_on_uploaded_rows_only(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $heading = ParameterContentRow::create([
            'parameter_id' => $this->parameter->id,
            'content' => 'IMPLEMENTATION',
            'sort_order' => 0,
        ]);

        $this->assertTrue($heading->isSectionHeading());

        $areas = $this->getJson('/api/users/me/areas')->assertStatus(200)->json('data');
        $this->assertTrue($areas[0]['canUpload']);
        $this->assertFalse($this->memberCanUpload());

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Row evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $this->area->refresh();
        $this->assertSame(100, $this->area->progress_percent);
    }

    public function test_remove_deletes_all_row_files_and_lowers_progress(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Row evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $this->deleteJson("/api/parameter-rows/{$this->row->id}/documents")
            ->assertStatus(200)
            ->assertJsonPath('data.hasFile', false);

        $this->assertSame(0, Document::where('content_row_id', $this->row->id)->count());
        $this->area->refresh();
        $this->assertSame(0, $this->area->progress_percent);
    }

    public function test_chair_can_remove_one_file_from_a_row_without_deleting_the_others(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $first = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'First evidence',
            'file' => UploadedFile::fake()->create('first.pdf', 40, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        $second = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Second evidence',
            'file' => UploadedFile::fake()->create('second.pdf', 40, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        $this->deleteJson("/api/documents/{$first}")->assertStatus(200);

        $this->assertNull(Document::find($first));
        $this->assertNotNull(Document::find($second));
        $this->assertSame(1, Document::where('content_row_id', $this->row->id)->count());
        $this->area->refresh();
        $this->assertSame(100, $this->area->progress_percent);
    }

    public function test_chair_submit_moves_area_review_from_draft_to_submitted(): void
    {
        Sanctum::actingAs($this->chair);

        $this->postJson("/api/accreditation-areas/{$this->area->id}/submit-review")
            ->assertStatus(200)
            ->assertJsonPath('data.currentStatus', 'Submitted');

        $this->assertDatabaseHas('reviews', [
            'area_id' => $this->area->id,
            'cycle_id' => $this->area->cycle_id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->chair->id,
        ]);

        $this->postJson("/api/accreditation-areas/{$this->area->id}/submit-review")
            ->assertStatus(422);
    }

    public function test_vpaa_can_delete_a_content_row_and_qa_cannot(): void
    {
        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/parameter-rows/{$this->row->id}")->assertStatus(403);

        Sanctum::actingAs($this->qa);
        $this->deleteJson("/api/parameter-rows/{$this->row->id}")->assertStatus(403);

        $vpaa = User::factory()->create();
        $vpaa->assignRole('VPAA');
        Sanctum::actingAs($vpaa);
        $this->deleteJson("/api/parameter-rows/{$this->row->id}")->assertStatus(200);
    }

    private function memberCanUpload(): bool
    {
        Sanctum::actingAs($this->member);
        $areas = $this->getJson('/api/users/me/areas')->assertStatus(200)->json('data');

        Sanctum::actingAs($this->chair);

        return (bool) ($areas[0]['canUpload'] ?? false);
    }
}
