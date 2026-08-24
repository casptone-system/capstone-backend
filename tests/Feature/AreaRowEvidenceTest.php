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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AreaRowEvidenceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_area_chair_can_upload_a_file_to_a_content_row(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $response = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Row evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.contentRowId', $this->row->id)
            ->assertJsonPath('data.areaId', $this->area->id);

        $this->area->refresh();
        $this->assertSame(0, $this->area->progress_percent);
    }

    public function test_area_member_cannot_upload_a_file_to_a_content_row(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->member);

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Member upload',
            'file' => UploadedFile::fake()->create('member.pdf', 100, 'application/pdf'),
        ])->assertStatus(403);
    }

    public function test_area_member_can_mark_a_row_done(): void
    {
        Sanctum::actingAs($this->member);

        $this->patchJson("/api/parameter-rows/{$this->row->id}/status", ['is_done' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.isDone', true);

        $this->area->refresh();
        $this->assertSame(0, $this->area->progress_percent);
    }

    public function test_progress_requires_both_done_and_chair_file(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Row evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $this->area->refresh();
        $this->assertSame(0, $this->area->progress_percent);

        Sanctum::actingAs($this->member);
        $this->patchJson("/api/parameter-rows/{$this->row->id}/status", ['is_done' => true])
            ->assertStatus(200);

        $this->area->refresh();
        $this->assertSame(100, $this->area->progress_percent);

        Sanctum::actingAs($this->chair);
        $areas = $this->getJson('/api/users/me/areas')->assertStatus(200)->json('data');
        $this->assertSame(100, $areas[0]['progressPercent']);
        $this->assertTrue($areas[0]['canUpload']);
    }

    public function test_qa_can_delete_a_content_row_and_faculty_cannot(): void
    {
        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/parameter-rows/{$this->row->id}")->assertStatus(403);

        Sanctum::actingAs($this->qa);
        $this->deleteJson("/api/parameter-rows/{$this->row->id}")->assertStatus(200);

        $this->assertDatabaseMissing('parameter_content_rows', ['id' => $this->row->id]);
    }

    public function test_program_chair_can_preview_area_row_uploads(): void
    {
        Storage::fake('local');

        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        $programChair = User::factory()->create(['program_id' => $this->program->id]);
        $programChair->assignRole('Program Chair');
        $programChair->assignRole('Faculty');
        $this->program->update(['chair_id' => $programChair->id]);

        Sanctum::actingAs($this->chair);
        $documentId = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Row evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($programChair);
        $this->getJson("/api/documents?area_id={$this->area->id}&per_page=100")
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $documentId]);

        $this->get("/api/documents/{$documentId}/preview")
            ->assertStatus(200)
            ->assertHeader('content-disposition');
    }

    public function test_rows_include_the_linked_document(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->chair);

        $documentId = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'content_row_id' => $this->row->id,
            'title' => 'Row evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        $row = $this->getJson("/api/parameters/{$this->parameter->id}/rows")
            ->assertStatus(200)
            ->json('data.0');

        $this->assertTrue($row['hasFile']);
        $this->assertSame($documentId, $row['document']['id']);
        $this->assertInstanceOf(Document::class, Document::find($documentId));
    }
}
