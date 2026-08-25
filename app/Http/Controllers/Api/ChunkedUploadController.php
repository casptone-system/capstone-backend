<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\AccreditationArea;
use App\Models\ChunkedUpload;
use App\Models\Document;
use App\Models\RoleStorageFile;
use App\Models\RoleStorageFolder;
use App\Models\User;
use App\Notifications\DocumentUploadedNotification;
use App\Services\AreaProgressService;
use App\Services\EvidenceStorage;
use App\Support\AreaDocumentRules;
use App\Support\AreaEvidenceGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChunkedUploadController extends Controller
{
    public function __construct(private EvidenceStorage $evidenceStorage)
    {
    }

    public function initiate(Request $request)
    {
        $this->pruneExpired();

        $validated = $request->validate([
            'purpose' => ['required', Rule::in([ChunkedUpload::PURPOSE_DOCUMENT, ChunkedUpload::PURPOSE_ROLE_STORAGE])],
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'total_size' => ['required', 'integer', 'min:1'],
            'total_chunks' => ['required', 'integer', 'min:1'],
            'checksum' => ['nullable', 'string', 'size:64'],
            'folder_id' => ['required_if:purpose,'.ChunkedUpload::PURPOSE_ROLE_STORAGE, 'nullable', 'integer', 'exists:role_storage_folders,id'],
            'role' => ['nullable', 'string', 'max:50'],
            'program_id' => ['required_if:purpose,'.ChunkedUpload::PURPOSE_DOCUMENT, 'nullable', 'exists:programs,id'],
            'area_id' => ['nullable', 'exists:accreditation_areas,id'],
            'task_id' => ['nullable', 'exists:tasks,id'],
            'content_row_id' => ['nullable', 'exists:parameter_content_rows,id'],
            'title' => ['required_if:purpose,'.ChunkedUpload::PURPOSE_DOCUMENT, 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'school_year' => ['nullable', 'string', 'max:20'],
            'document_id' => ['nullable', 'exists:documents,id'],
        ]);

        $extension = strtolower(pathinfo($validated['original_name'], PATHINFO_EXTENSION));
        $maxBytes = $this->evidenceStorage->maxUploadKilobytes($validated['mime_type'] ?? null, $extension) * 1024;

        if ((int) $validated['total_size'] > $maxBytes) {
            return response()->json([
                'success' => false,
                'message' => 'This file exceeds the allowed upload size.',
            ], 422);
        }

        if ($validated['purpose'] === ChunkedUpload::PURPOSE_DOCUMENT) {
            $document = ! empty($validated['document_id'])
                ? Document::findOrFail($validated['document_id'])
                : new Document;

            if ($document->exists) {
                $this->authorize('replace', $document);
                if ($document->area_id || $document->content_row_id) {
                    $area = $document->area ?: AreaEvidenceGate::resolveArea($document->area_id, $document->content_row_id);
                    AreaEvidenceGate::assertCanUpload($request->user(), $area);
                    AreaDocumentRules::assertPdfMeta(
                        $validated['original_name'],
                        $validated['mime_type'] ?? null,
                        (int) $validated['total_size']
                    );
                }
            } else {
                $this->authorize('create', Document::class);
                $area = AreaEvidenceGate::resolveArea(
                    isset($validated['area_id']) ? (int) $validated['area_id'] : null,
                    isset($validated['content_row_id']) ? (int) $validated['content_row_id'] : null
                );
                if ($area) {
                    AreaEvidenceGate::assertCanUpload($request->user(), $area);
                    AreaDocumentRules::assertPdfMeta(
                        $validated['original_name'],
                        $validated['mime_type'] ?? null,
                        (int) $validated['total_size']
                    );
                    if (empty($validated['document_id'])) {
                        AreaDocumentRules::assertRowHasCapacity(
                            isset($validated['content_row_id']) ? (int) $validated['content_row_id'] : null
                        );
                    }
                    $validated['area_id'] = $area->id;
                }
            }
        } else {
            $folder = RoleStorageFolder::findOrFail($validated['folder_id']);
            abort_unless($folder->user_id === $request->user()->id, 403, 'You are not allowed to upload to this folder.');
            $validated['metadata']['folder_id'] = $folder->id;
            $validated['metadata']['role'] = $folder->role;
        }

        $chunkSize = $this->evidenceStorage->chunkSizeBytes();
        $expectedChunks = (int) ceil($validated['total_size'] / $chunkSize);

        if ((int) $validated['total_chunks'] !== $expectedChunks) {
            return response()->json([
                'success' => false,
                'message' => 'total_chunks does not match the configured chunk size.',
                'data' => [
                    'chunk_size' => $chunkSize,
                    'expected_chunks' => $expectedChunks,
                ],
            ], 422);
        }

        $upload = ChunkedUpload::create([
            'user_id' => $request->user()->id,
            'purpose' => $validated['purpose'],
            'original_name' => $validated['original_name'],
            'mime_type' => $validated['mime_type'] ?? null,
            'extension' => $extension,
            'total_size' => $validated['total_size'],
            'chunk_size' => $chunkSize,
            'total_chunks' => $validated['total_chunks'],
            'received_chunks' => [],
            'status' => ChunkedUpload::STATUS_PENDING,
            'checksum' => $validated['checksum'] ?? null,
            'metadata' => array_filter([
                'folder_id' => $validated['folder_id'] ?? ($validated['metadata']['folder_id'] ?? null),
                'role' => $validated['role'] ?? ($validated['metadata']['role'] ?? null),
                'program_id' => $validated['program_id'] ?? null,
                'area_id' => $validated['area_id'] ?? null,
                'task_id' => $validated['task_id'] ?? null,
                'content_row_id' => $validated['content_row_id'] ?? null,
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'school_year' => $validated['school_year'] ?? null,
                'document_id' => $validated['document_id'] ?? null,
            ], fn ($value) => $value !== null),
            'expires_at' => now()->addDay(),
        ]);

        Storage::disk('local')->makeDirectory($this->chunkDirectory($upload));

        return response()->json([
            'success' => true,
            'message' => 'Chunked upload initiated.',
            'data' => [
                'upload_id' => $upload->id,
                'chunk_size' => $upload->chunk_size,
                'total_chunks' => $upload->total_chunks,
                'expires_at' => $upload->expires_at,
            ],
        ], 201);
    }

    public function config()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'evidence_disk' => $this->evidenceStorage->diskName(),
                'document_upload_max_bytes' => $this->evidenceStorage->documentUploadMaxKilobytes() * 1024,
                'media_upload_max_bytes' => $this->evidenceStorage->mediaUploadMaxKilobytes() * 1024,
                'chunk_size_bytes' => $this->evidenceStorage->chunkSizeBytes(),
                'chunk_threshold_bytes' => $this->evidenceStorage->chunkThresholdBytes(),
            ],
        ]);
    }

    public function storeChunk(Request $request, ChunkedUpload $upload)
    {
        $this->assertOwner($request, $upload);

        $validated = $request->validate([
            'chunk_index' => ['required', 'integer', 'min:0', 'max:'.($upload->total_chunks - 1)],
            'chunk' => ['required', 'file'],
        ]);

        $chunk = $request->file('chunk');
        $index = (int) $validated['chunk_index'];
        $isLast = $index === $upload->total_chunks - 1;

        if ($chunk->getSize() > $upload->chunk_size || (! $isLast && $chunk->getSize() !== $upload->chunk_size) || $chunk->getSize() < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Chunk size is invalid.',
            ], 422);
        }

        Storage::disk('local')->putFileAs(
            $this->chunkDirectory($upload),
            $chunk,
            (string) $index
        );

        $upload->markChunkReceived($index);

        return response()->json([
            'success' => true,
            'message' => 'Chunk stored.',
            'data' => [
                'upload_id' => $upload->id,
                'received_chunks' => count($upload->received_chunks ?? []),
                'total_chunks' => $upload->total_chunks,
            ],
        ], 200);
    }

    public function complete(Request $request, ChunkedUpload $upload)
    {
        $this->assertOwner($request, $upload);

        if ($upload->status === ChunkedUpload::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'This upload was already completed.',
            ], 409);
        }

        if (! $upload->isComplete()) {
            return response()->json([
                'success' => false,
                'message' => 'Not all chunks have been received.',
                'data' => [
                    'received_chunks' => count($upload->received_chunks ?? []),
                    'total_chunks' => $upload->total_chunks,
                ],
            ], 422);
        }

        $assembledPath = $this->chunkDirectory($upload).'/assembled';
        $this->assembleChunks($upload, $assembledPath);

        $assembledSize = Storage::disk('local')->size($assembledPath);

        if ($assembledSize !== $upload->total_size) {
            $upload->update(['status' => ChunkedUpload::STATUS_FAILED]);

            return response()->json([
                'success' => false,
                'message' => 'Assembled file size does not match the declared total.',
            ], 422);
        }

        $checksum = $this->evidenceStorage->checksum($assembledPath, 'local');

        if ($upload->checksum && ! hash_equals(strtolower($upload->checksum), $checksum)) {
            $upload->update(['status' => ChunkedUpload::STATUS_FAILED]);
            Storage::disk('local')->delete($assembledPath);

            return response()->json([
                'success' => false,
                'message' => 'Checksum mismatch after assembly.',
            ], 422);
        }

        try {
            $result = $upload->purpose === ChunkedUpload::PURPOSE_ROLE_STORAGE
                ? $this->completeRoleStorage($request, $upload, $assembledPath, $checksum)
                : $this->completeDocument($request, $upload, $assembledPath, $checksum);
        } finally {
            Storage::disk('local')->deleteDirectory($this->chunkDirectory($upload));
        }

        $upload->update([
            'status' => ChunkedUpload::STATUS_COMPLETED,
            'checksum' => $checksum,
        ]);

        return $result;
    }

    public function destroy(Request $request, ChunkedUpload $upload)
    {
        $this->assertOwner($request, $upload);

        Storage::disk('local')->deleteDirectory($this->chunkDirectory($upload));
        $upload->update(['status' => ChunkedUpload::STATUS_ABORTED]);
        $upload->delete();

        return response()->json([
            'success' => true,
            'message' => 'Chunked upload aborted.',
        ], 200);
    }

    private function completeRoleStorage(Request $request, ChunkedUpload $upload, string $assembledPath, string $checksum)
    {
        $metadata = $upload->metadata ?? [];
        $folder = RoleStorageFolder::findOrFail($metadata['folder_id']);
        abort_unless($folder->user_id === $request->user()->id, 403, 'You are not allowed to upload to this folder.');

        $storedName = Str::uuid()->toString().'.'.($upload->extension ?: 'bin');
        $relativeDirectory = 'role-storage/'.$request->user()->id.'/'.$folder->role.'/'.$folder->id;
        $targetPath = $relativeDirectory.'/'.$storedName;

        $this->putAssembled($assembledPath, $targetPath);

        $storageFile = RoleStorageFile::create([
            'user_id' => $request->user()->id,
            'folder_id' => $folder->id,
            'role' => $folder->role,
            'name' => $upload->original_name,
            'original_name' => $upload->original_name,
            'mime_type' => $upload->mime_type,
            'file_size' => $upload->total_size,
            'file_path' => $targetPath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully.',
            'data' => $storageFile,
        ], 201);
    }

    private function completeDocument(Request $request, ChunkedUpload $upload, string $assembledPath, string $checksum)
    {
        $metadata = $upload->metadata ?? [];

        if (! empty($metadata['document_id'])) {
            $document = Document::findOrFail($metadata['document_id']);
            $this->authorize('replace', $document);
            $newVersion = $document->current_version + 1;
            $targetPath = "documents/{$document->id}/v{$newVersion}/{$upload->original_name}";
            $this->putAssembled($assembledPath, $targetPath);

            $document->versions()->create([
                'version' => $newVersion,
                'file_path' => $targetPath,
                'original_name' => $upload->original_name,
                'mime_type' => $upload->mime_type,
                'file_size' => $upload->total_size,
                'uploaded_by' => $request->user()->id,
            ]);
            $document->update(['current_version' => $newVersion]);

            if ($document->content_row_id) {
                app(AreaProgressService::class)->refreshForContentRow((int) $document->content_row_id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Document replaced successfully.',
                'data' => new DocumentResource($document->load('program', 'area', 'task', 'uploader', 'versions')),
            ], 200);
        }

        $this->authorize('create', Document::class);

        $area = AreaEvidenceGate::resolveArea(
            isset($metadata['area_id']) ? (int) $metadata['area_id'] : null,
            isset($metadata['content_row_id']) ? (int) $metadata['content_row_id'] : null
        );
        if ($area) {
            AreaEvidenceGate::assertCanUpload($request->user(), $area);
        }

        $document = Document::create([
            'program_id' => $metadata['program_id'],
            'area_id' => $area?->id ?? ($metadata['area_id'] ?? null),
            'task_id' => $metadata['task_id'] ?? null,
            'content_row_id' => $metadata['content_row_id'] ?? null,
            'title' => $metadata['title'],
            'description' => $metadata['description'] ?? null,
            'school_year' => $metadata['school_year'] ?? null,
            'uploaded_by' => $request->user()->id,
            'current_version' => 1,
            'status' => 'Active',
        ]);

        $targetPath = "documents/{$document->id}/v1/{$upload->original_name}";
        $this->putAssembled($assembledPath, $targetPath);

        $document->versions()->create([
            'version' => 1,
            'file_path' => $targetPath,
            'original_name' => $upload->original_name,
            'mime_type' => $upload->mime_type,
            'file_size' => $upload->total_size,
            'uploaded_by' => $request->user()->id,
        ]);

        $this->notifyDocumentUploaded($document, $request->user());

        if (! empty($document->content_row_id)) {
            app(AreaProgressService::class)->refreshForContentRow((int) $document->content_row_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded successfully.',
            'data' => new DocumentResource($document->load('program', 'area', 'task', 'uploader', 'versions')),
        ], 201);
    }

    private function putAssembled(string $assembledPath, string $targetPath): void
    {
        $stream = Storage::disk('local')->readStream($assembledPath);

        if ($stream === false) {
            throw new \RuntimeException('Unable to read assembled upload.');
        }

        $written = $this->evidenceStorage->writeStream($targetPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $written) {
            throw new \RuntimeException('Unable to write assembled upload to evidence storage.');
        }
    }

    private function assembleChunks(ChunkedUpload $upload, string $assembledPath): void
    {
        $directory = $this->chunkDirectory($upload);
        $output = Storage::disk('local')->path($assembledPath);
        Storage::disk('local')->makeDirectory($directory);

        $handle = fopen($output, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open assembled file for writing.');
        }

        for ($index = 0; $index < $upload->total_chunks; $index++) {
            $chunkPath = Storage::disk('local')->path($directory.'/'.$index);
            $chunkHandle = fopen($chunkPath, 'rb');

            if ($chunkHandle === false) {
                fclose($handle);
                throw new \RuntimeException("Missing chunk {$index}.");
            }

            stream_copy_to_stream($chunkHandle, $handle);
            fclose($chunkHandle);
        }

        fclose($handle);
    }

    private function chunkDirectory(ChunkedUpload $upload): string
    {
        return 'chunked-uploads/'.$upload->id;
    }

    private function assertOwner(Request $request, ChunkedUpload $upload): void
    {
        abort_unless($upload->user_id === $request->user()->id, 403, 'You are not allowed to access this upload.');
        abort_if($upload->expires_at && $upload->expires_at->isPast(), 410, 'This upload session has expired.');
        abort_if($upload->status === ChunkedUpload::STATUS_ABORTED, 410, 'This upload session was aborted.');
    }

    private function pruneExpired(): void
    {
        $expired = ChunkedUpload::query()
            ->where('status', ChunkedUpload::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $upload) {
            Storage::disk('local')->deleteDirectory($this->chunkDirectory($upload));
            $upload->update(['status' => ChunkedUpload::STATUS_ABORTED]);
        }
    }

    private function notifyDocumentUploaded(Document $document, User $uploader): void
    {
        $recipientIds = collect();

        if ($document->area_id) {
            $area = AccreditationArea::with('chair', 'members.user')->find($document->area_id);

            if ($area) {
                if ($area->chair_id && $area->chair_id !== $uploader->id) {
                    $recipientIds->push($area->chair_id);
                }

                foreach ($area->members as $member) {
                    if ($member->user_id && $member->user_id !== $uploader->id) {
                        $recipientIds->push($member->user_id);
                    }
                }
            }
        }

        $recipientIds = $recipientIds->unique();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $recipientIds)->get();
        $document->load('uploader');

        foreach ($users as $user) {
            $user->notify(new DocumentUploadedNotification($document, $uploader->name));
        }
    }
}
