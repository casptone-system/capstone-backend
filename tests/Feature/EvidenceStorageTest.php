<?php

namespace Tests\Feature;

use App\Models\ChunkedUpload;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Program;
use App\Models\RoleStorageFile;
use App\Models\RoleStorageFolder;
use App\Models\StorageMigrationItem;
use App\Models\User;
use App\Services\EvidenceStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EvidenceStorageTest extends TestCase
{
    private User $user;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.evidence_disk', 'local');
        config()->set('filesystems.chunk_size_bytes', 1024);
        config()->set('filesystems.document_upload_max_kb', 51200);
        config()->set('filesystems.media_upload_max_kb', 1048576);

        Storage::fake('local');
        Storage::fake('s3');

        $this->user = User::factory()->create();
        Role::firstOrCreate(['name' => 'faculty', 'guard_name' => 'web']);
        $this->user->assignRole('faculty');
        Sanctum::actingAs($this->user);

        $this->program = Program::factory()->create();
    }

    public function test_document_upload_uses_the_evidence_disk(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 120, 'application/pdf');

        $response = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'title' => 'R2 Evidence Report',
            'file' => $file,
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $document = Document::where('title', 'R2 Evidence Report')->firstOrFail();
        $path = $document->versions()->firstOrFail()->file_path;

        $this->assertSame("documents/{$document->id}/v1/report.pdf", $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_document_download_falls_back_to_local_when_evidence_disk_is_s3(): void
    {
        config()->set('filesystems.evidence_disk', 's3');

        $document = Document::factory()->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
        ]);

        $path = "documents/{$document->id}/v1/legacy.pdf";
        Storage::disk('local')->put($path, 'legacy-bytes');

        $document->versions()->create([
            'version' => 1,
            'file_path' => $path,
            'original_name' => 'legacy.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->get("/api/documents/{$document->id}/download");

        $response->assertOk();
        $this->assertStringContainsString('legacy.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_role_storage_chunked_upload_assembles_and_stores_on_evidence_disk(): void
    {
        $folder = RoleStorageFolder::create([
            'user_id' => $this->user->id,
            'role' => 'faculty',
            'name' => 'Videos',
        ]);

        $contents = str_repeat('A', 2500);
        $fileName = 'walkthrough.mp4';

        $initiate = $this->postJson('/api/uploads/initiate', [
            'purpose' => ChunkedUpload::PURPOSE_ROLE_STORAGE,
            'original_name' => $fileName,
            'mime_type' => 'video/mp4',
            'total_size' => strlen($contents),
            'total_chunks' => 3,
            'folder_id' => $folder->id,
            'role' => 'faculty',
        ]);

        $initiate->assertCreated();
        $uploadId = $initiate->json('data.upload_id');

        foreach ([0, 1, 2] as $index) {
            $chunk = substr($contents, $index * 1024, 1024);
            $response = $this->post("/api/uploads/{$uploadId}/chunks", [
                'chunk_index' => $index,
                'chunk' => UploadedFile::fake()->createWithContent("chunk-{$index}.bin", $chunk),
            ]);
            $response->assertOk();
        }

        $complete = $this->postJson("/api/uploads/{$uploadId}/complete");
        $complete->assertCreated()->assertJsonPath('data.name', $fileName);

        $stored = RoleStorageFile::where('name', $fileName)->firstOrFail();
        $this->assertSame(strlen($contents), $stored->file_size);
        Storage::disk('local')->assertExists($stored->file_path);
        $this->assertSame($contents, Storage::disk('local')->get($stored->file_path));
        $this->assertTrue(str_starts_with($stored->file_path, 'role-storage/'.$this->user->id.'/faculty/'.$folder->id.'/'));
    }

    public function test_chunked_upload_rejects_non_media_files_over_document_cap(): void
    {
        config()->set('filesystems.document_upload_max_kb', 1);

        $folder = RoleStorageFolder::create([
            'user_id' => $this->user->id,
            'role' => 'faculty',
            'name' => 'Docs',
        ]);

        $response = $this->postJson('/api/uploads/initiate', [
            'purpose' => ChunkedUpload::PURPOSE_ROLE_STORAGE,
            'original_name' => 'huge.pdf',
            'mime_type' => 'application/pdf',
            'total_size' => 2048,
            'total_chunks' => 2,
            'folder_id' => $folder->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_chunked_upload_is_rejected(): void
    {
        auth()->forgetGuards();

        $this->postJson('/api/uploads/initiate', [
            'purpose' => 'role_storage',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'total_size' => 2048,
            'total_chunks' => 2,
            'folder_id' => 1,
        ])->assertUnauthorized();
    }

    public function test_migrate_evidence_command_copies_and_verifies_checksums(): void
    {
        config()->set('filesystems.disks.s3.bucket', 'adams-evidence');

        $document = Document::factory()->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
        ]);

        $path = "documents/{$document->id}/v1/migrate.pdf";
        Storage::disk('local')->put($path, 'byte-for-byte-payload');

        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => 1,
            'file_path' => $path,
            'original_name' => 'migrate.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 21,
            'uploaded_by' => $this->user->id,
        ]);

        $this->artisan('storage:migrate-evidence', [
            '--direction' => 'to-r2',
            '--sync' => true,
        ])->assertSuccessful();

        Storage::disk('s3')->assertExists($path);
        $this->assertSame('byte-for-byte-payload', Storage::disk('s3')->get($path));

        $item = StorageMigrationItem::firstOrFail();
        $this->assertSame(StorageMigrationItem::STATUS_VERIFIED, $item->status);
        $this->assertNotEmpty($item->source_checksum);
        $this->assertSame($item->source_checksum, $item->destination_checksum);
        Storage::disk('local')->assertExists($path);
    }

    public function test_migrate_evidence_command_deletes_source_only_after_verify(): void
    {
        config()->set('filesystems.disks.s3.bucket', 'adams-evidence');

        $document = Document::factory()->create([
            'program_id' => $this->program->id,
            'uploaded_by' => $this->user->id,
        ]);

        $path = "documents/{$document->id}/v1/delete-after.pdf";
        Storage::disk('local')->put($path, 'verified-then-delete');

        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => 1,
            'file_path' => $path,
            'original_name' => 'delete-after.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'uploaded_by' => $this->user->id,
        ]);

        $this->artisan('storage:migrate-evidence', [
            '--direction' => 'to-r2',
            '--delete-source' => true,
            '--sync' => true,
        ])->assertSuccessful();

        Storage::disk('s3')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(
            StorageMigrationItem::STATUS_SOURCE_DELETED,
            StorageMigrationItem::firstOrFail()->status
        );
    }

    public function test_migrate_evidence_is_reversible_back_to_local(): void
    {
        config()->set('filesystems.disks.s3.bucket', 'adams-evidence');

        $folder = RoleStorageFolder::create([
            'user_id' => $this->user->id,
            'role' => 'faculty',
            'name' => 'Vault',
        ]);

        $path = 'role-storage/'.$this->user->id.'/faculty/'.$folder->id.'/clip.mp4';
        Storage::disk('s3')->put($path, 'r2-origin-bytes');

        RoleStorageFile::create([
            'user_id' => $this->user->id,
            'folder_id' => $folder->id,
            'role' => 'faculty',
            'name' => 'clip.mp4',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 15,
            'file_path' => $path,
        ]);

        $this->artisan('storage:migrate-evidence', [
            '--direction' => 'from-r2',
            '--sync' => true,
        ])->assertSuccessful();

        Storage::disk('local')->assertExists($path);
        $this->assertSame('r2-origin-bytes', Storage::disk('local')->get($path));
    }

    public function test_upload_config_endpoint_is_authenticated(): void
    {
        $this->getJson('/api/uploads/config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.chunk_size_bytes', 1024);
    }

    public function test_evidence_storage_checksum_matches_hash_file(): void
    {
        $path = 'documents/checksum.txt';
        Storage::disk('local')->put($path, 'abc123');

        $checksum = app(EvidenceStorage::class)->checksum($path, 'local');

        $this->assertSame(hash('sha256', 'abc123'), $checksum);
    }
}
