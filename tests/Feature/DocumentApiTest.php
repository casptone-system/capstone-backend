<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Document;
use App\Models\Program;
use App\Models\RoleStorageFile;
use App\Models\RoleStorageFolder;
use App\Models\Task;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Program $program;
    private AccreditationCycle $cycle;
    private AccreditationArea $area;
    private Task $task;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // Ensure a faculty role exists and assign to the test user so policies allow document actions
        Role::firstOrCreate(['name' => 'faculty', 'guard_name' => 'web']);
        $this->user->assignRole('faculty');
        Sanctum::actingAs($this->user);

        $this->program = Program::factory()->create();
        $this->cycle = AccreditationCycle::factory()->create(['program_id' => $this->program->id]);
        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area I: Vision, Mission, Goals',
        ]);
        $this->task = Task::factory()->create([
            'area_id' => $this->area->id,
            'title' => 'Prepare documentation',
            'created_by' => $this->user->id,
        ]);

        $this->document = Document::factory()->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'task_id' => $this->task->id,
            'title' => 'Faculty CV Compilation',
            'school_year' => '2024-2025',
            'uploaded_by' => $this->user->id,
        ]);

        // Create an initial version record for the document
        $this->document->versions()->create([
            'version' => 1,
            'file_path' => 'documents/' . $this->document->id . '/v1/faculty-cv.pdf',
            'original_name' => 'faculty-cv.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->user->id,
        ]);
    }

    public function test_index_returns_paginated_documents(): void
    {
        Document::factory(3)->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/documents');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'programId', 'areaId', 'taskId', 'title', 'description', 'schoolYear', 'uploadedBy', 'currentVersion', 'status', 'createdAt', 'updatedAt'],
                ],
            ]);
    }

    public function test_index_can_filter_by_program_id(): void
    {
        $otherProgram = Program::factory()->create();
        Document::factory(2)->create(['program_id' => $this->program->id, 'uploaded_by' => $this->user->id]);
        Document::factory(1)->create(['program_id' => $otherProgram->id, 'uploaded_by' => $this->user->id]);

        $response = $this->getJson('/api/documents?program_id=' . $this->program->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_index_can_filter_by_area_id(): void
    {
        $otherArea = AccreditationArea::factory()->create(['cycle_id' => $this->cycle->id]);
        Document::factory(2)->create([
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'uploaded_by' => $this->user->id,
        ]);
        Document::factory(1)->create([
            'program_id' => $this->program->id,
            'area_id' => $otherArea->id,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/documents?area_id=' . $this->area->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_index_can_filter_by_status(): void
    {
        Document::factory(2)->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
            'status' => 'Active',
        ]);
        Document::factory(1)->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
            'status' => 'Archived',
        ]);

        $response = $this->getJson('/api/documents?status=Archived');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_can_filter_by_school_year(): void
    {
        Document::factory(2)->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
            'school_year' => '2026-2027',
        ]);
        Document::factory(1)->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
            'school_year' => '2025-2026',
        ]);

        $response = $this->getJson('/api/documents?school_year=2026-2027');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_can_search_by_title(): void
    {
        Document::factory()->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
            'title' => 'Research Publication',
        ]);

        $response = $this->getJson('/api/documents?search=Faculty');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_store_uploads_document(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'task_id' => $this->task->id,
            'title' => 'Accreditation Report',
            'description' => 'Annual accreditation report',
            'school_year' => '2026-2027',
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Accreditation Report')
            ->assertJsonPath('data.description', 'Annual accreditation report')
            ->assertJsonPath('data.schoolYear', '2026-2027')
            ->assertJsonPath('data.currentVersion', 1)
            ->assertJsonPath('data.status', 'Active');

        $this->assertDatabaseHas('documents', [
            'title' => 'Accreditation Report',
            'program_id' => $this->program->id,
            'current_version' => 1,
        ]);

        $this->assertDatabaseHas('document_versions', [
            'version' => 1,
            'original_name' => 'document.pdf',
        ]);
    }

    public function test_faculty_can_link_role_storage_file_as_accreditation_evidence(): void
    {
        Storage::fake('local');

        $folder = RoleStorageFolder::create([
            'user_id' => $this->user->id,
            'role' => 'faculty',
            'name' => 'My Documents',
        ]);

        $storedPath = Storage::disk('local')->put('role-storage/' . $this->user->id . '/faculty/' . $folder->id . '/sample.pdf', 'pdf-content');

        $storageFile = RoleStorageFile::create([
            'user_id' => $this->user->id,
            'folder_id' => $folder->id,
            'role' => 'faculty',
            'name' => 'sample.pdf',
            'original_name' => 'sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 11,
            'file_path' => $storedPath,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/role-storage/files/' . $storageFile->id . '/link-evidence', [
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'task_id' => $this->task->id,
            'title' => 'Linked Evidence Sample',
            'description' => 'Created from faculty personal storage.',
            'school_year' => '2026-2027',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Linked Evidence Sample')
            ->assertJsonPath('data.programId', $this->program->id)
            ->assertJsonPath('data.areaId', $this->area->id)
            ->assertJsonPath('data.taskId', $this->task->id);

        $this->assertDatabaseHas('documents', [
            'title' => 'Linked Evidence Sample',
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/documents', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_id', 'title', 'file']);
    }

    public function test_store_validates_file_size(): void
    {
        Storage::fake('local');

        // Create a file larger than 50MB
        $file = UploadedFile::fake()->create('large.pdf', 60000, 'application/pdf');

        $response = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'title' => 'Large File Test',
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_show_returns_document_details(): void
    {
        $response = $this->getJson('/api/documents/' . $this->document->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->document->id)
            ->assertJsonPath('data.title', 'Faculty CV Compilation')
            ->assertJsonPath('data.currentVersion', 1);
    }

    public function test_update_modifies_document_metadata(): void
    {
        $response = $this->patchJson('/api/documents/' . $this->document->id, [
            'title' => 'Updated Document Title',
            'description' => 'Updated description',
            'school_year' => '2027-2028',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Updated Document Title')
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.schoolYear', '2027-2028');

        $this->assertDatabaseHas('documents', [
            'id' => $this->document->id,
            'title' => 'Updated Document Title',
            'description' => 'Updated description',
            'school_year' => '2027-2028',
        ]);
    }

    public function test_update_can_change_status_to_archived(): void
    {
        $response = $this->patchJson('/api/documents/' . $this->document->id, [
            'status' => 'Archived',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'Archived');

        $this->assertDatabaseHas('documents', [
            'id' => $this->document->id,
            'status' => 'Archived',
        ]);
    }

    public function test_destroy_deletes_document_and_files(): void
    {
        Storage::fake('local');

        // Create a document with a stored file
        $doc = Document::factory()->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
        ]);
        $doc->versions()->create([
            'version' => 1,
            'file_path' => 'documents/' . $doc->id . '/v1/test.pdf',
            'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->deleteJson('/api/documents/' . $doc->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('documents', ['id' => $doc->id]);
        $this->assertDatabaseMissing('document_versions', ['document_id' => $doc->id]);
    }

    public function test_replace_creates_new_version(): void
    {
        Storage::fake('local');

        $newFile = UploadedFile::fake()->create('updated-v2.pdf', 200, 'application/pdf');

        $response = $this->postJson('/api/documents/' . $this->document->id . '/replace', [
            'file' => $newFile,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Document replaced successfully.')
            ->assertJsonPath('data.currentVersion', 2);

        $this->assertDatabaseHas('documents', [
            'id' => $this->document->id,
            'current_version' => 2,
        ]);

        $this->assertDatabaseHas('document_versions', [
            'document_id' => $this->document->id,
            'version' => 2,
            'original_name' => 'updated-v2.pdf',
        ]);
    }

    public function test_replace_validates_file_required(): void
    {
        $response = $this->postJson('/api/documents/' . $this->document->id . '/replace', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_versions_returns_version_history(): void
    {
        // Add a second version
        $this->document->versions()->create([
            'version' => 2,
            'file_path' => 'documents/' . $this->document->id . '/v2/updated.pdf',
            'original_name' => 'updated.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/documents/' . $this->document->id . '/versions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_download_returns_file(): void
    {
        Storage::fake('local');

        // Create a file in the fake storage
        $filePath = 'documents/' . $this->document->id . '/v1/faculty-cv.pdf';
        Storage::disk('local')->put($filePath, 'fake file content');

        // Update the version to point to the fake file
        $version = $this->document->versions()->first();
        $version->update(['file_path' => $filePath]);

        $response = $this->getJson('/api/documents/' . $this->document->id . '/download');

        $response->assertStatus(200);
        // The response should be a file download
        $this->assertStringContainsString(
            $version->original_name,
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_download_with_specific_version(): void
    {
        Storage::fake('local');

        // Create version 2
        $v2Path = 'documents/' . $this->document->id . '/v2/updated.pdf';
        Storage::disk('local')->put($v2Path, 'updated content');

        $this->document->versions()->create([
            'version' => 2,
            'file_path' => $v2Path,
            'original_name' => 'updated.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/documents/' . $this->document->id . '/download?version=2');

        $response->assertStatus(200);
        $this->assertStringContainsString('updated.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_download_returns_404_if_version_not_found(): void
    {
        $response = $this->getJson('/api/documents/' . $this->document->id . '/download?version=99');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        auth()->forgetGuards();

        $response = $this->getJson('/api/documents');

        $response->assertStatus(401);
    }
}