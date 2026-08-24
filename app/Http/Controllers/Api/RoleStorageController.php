<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\RoleStorageFile;
use App\Models\RoleStorageFolder;
use App\Models\Task;
use App\Services\AreaProgressService;
use App\Services\EvidenceStorage;
use App\Support\AreaEvidenceGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RoleStorageController extends Controller
{
    public function __construct(private EvidenceStorage $evidenceStorage)
    {
    }
    protected array $allowedMimeTypes = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'video' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo'],
        'audio' => ['audio/mpeg', 'audio/wav', 'audio/x-m4a'],
        'text' => ['text/plain', 'text/csv'],
        'archive' => ['application/zip'],
    ];

    public function index(Request $request)
    {
        if (! Schema::hasTable('role_storage_folders') || ! Schema::hasTable('role_storage_files')) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Role storage is not initialized yet.',
            ], 200);
        }

        $user = $request->user();
        $role = $this->normalizeRole((string) $request->query('role', 'faculty'));
        $search = trim((string) $request->query('search', ''));
        $type = strtolower((string) $request->query('type', 'all'));
        $folderId = $request->query('folder_id');

        $foldersQuery = RoleStorageFolder::with(['files' => function ($query) use ($search, $type) {
            $query->whereNull('deleted_at')->orderBy('created_at', 'desc');

            if ($search !== '') {
                $query->where(function ($folderQuery) use ($search) {
                    $folderQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('original_name', 'like', "%{$search}%");
                });
            }

            if ($type !== 'all') {
                $query->where(function ($folderQuery) use ($type) {
                    foreach ($this->mimeMatches($type) as $mime) {
                        $folderQuery->orWhere('mime_type', $mime);
                    }
                });
            }
        }])
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->when($folderId !== null, fn ($query) => $query->where('id', $folderId))
            ->orderBy('name');

        $folders = $foldersQuery->get();

        return response()->json([
            'success' => true,
            'data' => $folders,
        ], 200);
    }

    public function storageSummary(Request $request)
    {
        $user = $request->user();
        $role = $this->normalizeRole((string) $request->query('role', 'faculty'));

        $files = RoleStorageFile::where('user_id', $user->id)
            ->where('role', $role)
            ->get();

        $totalSize = $files->sum('file_size');
        $totalFiles = $files->count();
        $documents = $files->filter(fn ($file) => str_contains((string) $file->mime_type, 'pdf') || str_contains((string) $file->mime_type, 'document') || str_contains((string) $file->mime_type, 'text') || str_contains((string) $file->mime_type, 'sheet') || str_contains((string) $file->mime_type, 'presentation'))
            ->sum('file_size');
        $images = $files->filter(fn ($file) => str_contains((string) $file->mime_type, 'image'))->sum('file_size');
        $videos = $files->filter(fn ($file) => str_contains((string) $file->mime_type, 'video'))->sum('file_size');
        $other = $files->filter(fn ($file) => ! str_contains((string) $file->mime_type, 'image') && ! str_contains((string) $file->mime_type, 'video') && ! str_contains((string) $file->mime_type, 'pdf') && ! str_contains((string) $file->mime_type, 'document') && ! str_contains((string) $file->mime_type, 'text') && ! str_contains((string) $file->mime_type, 'sheet') && ! str_contains((string) $file->mime_type, 'presentation'))->sum('file_size');

        return response()->json([
            'success' => true,
            'data' => [
                'role' => $role,
                'files' => $totalFiles,
                'total_storage_bytes' => (int) $totalSize,
                'total_storage_mb' => round($totalSize / 1024 / 1024, 2),
                'documents_bytes' => (int) $documents,
                'images_bytes' => (int) $images,
                'videos_bytes' => (int) $videos,
                'other_bytes' => (int) $other,
                'used_percent' => 0,
                'limit_mb' => 10240,
            ],
        ], 200);
    }

    public function store(Request $request)
    {
        if (! Schema::hasTable('role_storage_folders')) {
            return response()->json([
                'success' => false,
                'message' => 'Role storage is not initialized yet. Please run the database migration.',
            ], 503);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:dean,faculty,program-chair'],
            'parent_id' => ['nullable', 'integer', 'exists:role_storage_folders,id'],
        ]);

        $name = trim($validated['name']);
        $parentId = $validated['parent_id'] ?? null;

        if ($parentId) {
            $parentFolder = RoleStorageFolder::where('id', $parentId)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            abort_unless($parentFolder->role === $this->normalizeRole($validated['role']), 403, 'Folder role mismatch.');
        }

        $folder = RoleStorageFolder::create([
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'role' => $this->normalizeRole($validated['role']),
            'name' => $name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Folder created successfully.',
            'data' => $folder,
        ], 201);
    }

    public function upload(Request $request, RoleStorageFolder $folder)
    {
        if (! Schema::hasTable('role_storage_folders') || ! Schema::hasTable('role_storage_files')) {
            return response()->json([
                'success' => false,
                'message' => 'Role storage is not initialized yet. Please run the database migration.',
            ], 503);
        }

        $user = $request->user();

        abort_unless($folder->user_id === $user->id, 403, 'You are not allowed to upload to this folder.');
        abort_unless($folder->role === $this->normalizeRole((string) $request->query('role', $folder->role)), 403, 'Role mismatch.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->evidenceStorage->documentUploadMaxKilobytes()],
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        $allowedMimeTypes = array_merge(...array_values($this->allowedMimeTypes));

        if (! in_array($mimeType, $allowedMimeTypes, true) && ! in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'webm', 'avi', 'mp3', 'wav', 'm4a', 'zip', 'txt', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This file type is not allowed in faculty storage.',
            ], 422);
        }

        $storedName = Str::uuid()->toString() . '.' . ($extension ?: 'bin');
        $relativeDirectory = 'role-storage/' . $user->id . '/' . $folder->role . '/' . $folder->id;
        $storedPath = $this->evidenceStorage->putFileAs($relativeDirectory, $file, $storedName);

        $storageFile = RoleStorageFile::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'role' => $folder->role,
            'name' => $originalName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
            'file_path' => $storedPath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully.',
            'data' => $storageFile,
        ], 201);
    }

    public function update(Request $request, RoleStorageFile $file)
    {
        abort_unless($request->user()->id === $file->user_id, 403, 'You are not allowed to update this file.');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'integer', 'exists:role_storage_folders,id'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,archived,evidence'],
            'is_favorite' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['folder_id'])) {
            $targetFolder = RoleStorageFolder::where('id', $validated['folder_id'])
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            abort_unless($targetFolder->role === $file->role, 403, 'Folder role mismatch.');
            $file->folder_id = $targetFolder->id;
        }

        if (isset($validated['name'])) {
            $file->name = trim($validated['name']);
            $file->original_name = trim($validated['name']);
        }

        if (array_key_exists('status', $validated)) {
            $file->status = $validated['status'] ?? 'active';
        }

        if (array_key_exists('is_favorite', $validated)) {
            $file->is_favorite = (bool) $validated['is_favorite'];
        }

        $file->save();

        return response()->json([
            'success' => true,
            'message' => 'File updated successfully.',
            'data' => $file,
        ], 200);
    }

    public function renameFolder(Request $request, RoleStorageFolder $folder)
    {
        abort_unless($request->user()->id === $folder->user_id, 403, 'You are not allowed to rename this folder.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $folder->name = trim($validated['name']);
        $folder->save();

        return response()->json([
            'success' => true,
            'message' => 'Folder renamed successfully.',
            'data' => $folder,
        ], 200);
    }

    public function moveFolder(Request $request, RoleStorageFolder $folder)
    {
        abort_unless($request->user()->id === $folder->user_id, 403, 'You are not allowed to move this folder.');

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:role_storage_folders,id'],
        ]);

        $newParentId = $validated['parent_id'] ?? null;

        if ($newParentId && $this->wouldCreateCircularReference($folder, $newParentId)) {
            return response()->json([
                'success' => false,
                'message' => 'A folder cannot be moved into itself or one of its children.',
            ], 422);
        }

        if ($newParentId) {
            $newParent = RoleStorageFolder::where('id', $newParentId)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            abort_unless($newParent->role === $folder->role, 403, 'Folder role mismatch.');
        }

        $folder->parent_id = $newParentId;
        $folder->save();

        return response()->json([
            'success' => true,
            'message' => 'Folder moved successfully.',
            'data' => $folder,
        ], 200);
    }

    public function favoriteFile(Request $request, RoleStorageFile $file)
    {
        abort_unless($request->user()->id === $file->user_id, 403, 'You are not allowed to favorite this file.');

        $file->is_favorite = ! $file->is_favorite;
        $file->save();

        return response()->json([
            'success' => true,
            'message' => 'File favorite updated.',
            'data' => $file,
        ], 200);
    }

    public function trashFile(Request $request, RoleStorageFile $file)
    {
        abort_unless($request->user()->id === $file->user_id, 403, 'You are not allowed to archive this file.');

        $file->status = 'archived';
        $file->deleted_at = now();
        $file->save();

        return response()->json([
            'success' => true,
            'message' => 'File archived successfully.',
            'data' => $file,
        ], 200);
    }

    public function restoreFile(Request $request, RoleStorageFile $file)
    {
        abort_unless($request->user()->id === $file->user_id, 403, 'You are not allowed to restore this file.');

        $file->status = 'active';
        $file->deleted_at = null;
        $file->save();

        return response()->json([
            'success' => true,
            'message' => 'File restored successfully.',
            'data' => $file,
        ], 200);
    }

    public function linkEvidence(Request $request, RoleStorageFile $file)
    {
        abort_unless($request->user()->id === $file->user_id, 403, 'You are not allowed to link this file.');
        abort_unless($file->role === 'faculty', 403, 'Evidence linking is only available for faculty storage files.');

        $validated = $request->validate([
            'program_id' => ['required', 'exists:programs,id'],
            'area_id' => ['nullable', 'exists:accreditation_areas,id'],
            'task_id' => ['nullable', 'exists:tasks,id'],
            'content_row_id' => ['nullable', 'exists:parameter_content_rows,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'school_year' => ['nullable', 'string', 'max:20'],
        ]);

        if (! empty($validated['task_id'])) {
            $task = Task::find($validated['task_id']);
            abort_if(! $task, 404, 'Task not found.');

            if (! empty($validated['area_id']) && (int) $task->area_id !== (int) $validated['area_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected task does not belong to the selected area.',
                ], 422);
            }

            $validated['area_id'] = $task->area_id;
        }

        $area = AreaEvidenceGate::resolveArea(
            isset($validated['area_id']) ? (int) $validated['area_id'] : null,
            isset($validated['content_row_id']) ? (int) $validated['content_row_id'] : null
        );

        if ($area) {
            AreaEvidenceGate::assertCanUpload($request->user(), $area);

            if ((int) $area->cycle?->program_id !== (int) $validated['program_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected area does not belong to the selected program.',
                ], 422);
            }

            $validated['area_id'] = $area->id;
        }

        $document = Document::create([
            'program_id' => $validated['program_id'],
            'area_id' => $validated['area_id'] ?? null,
            'task_id' => $validated['task_id'] ?? null,
            'content_row_id' => $validated['content_row_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'school_year' => $validated['school_year'] ?? null,
            'uploaded_by' => $request->user()->id,
            'current_version' => 1,
            'status' => 'Active',
        ]);

        $document->versions()->create([
            'version' => 1,
            'file_path' => $file->file_path,
            'original_name' => $file->original_name ?? $file->name,
            'mime_type' => $file->mime_type,
            'file_size' => $file->file_size,
            'uploaded_by' => $request->user()->id,
        ]);

        $file->status = 'evidence';
        $file->save();

        if (! empty($document->content_row_id)) {
            app(AreaProgressService::class)->refreshForContentRow((int) $document->content_row_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Faculty file linked as accreditation evidence.',
            'data' => new \App\Http\Resources\DocumentResource($document->load('program', 'area', 'task', 'uploader', 'versions')),
        ], 201);
    }

    public function download(Request $request, RoleStorageFile $file)
    {
        if (! Schema::hasTable('role_storage_files')) {
            return response()->json([
                'success' => false,
                'message' => 'Role storage is not initialized yet. Please run the database migration.',
            ], 503);
        }

        abort_unless($request->user()->id === $file->user_id, 403, 'You are not allowed to access this file.');

        $response = $this->evidenceStorage->streamInline(
            $file->file_path,
            $file->original_name ?: $file->name,
            $file->mime_type
        );

        if ($response === null) {
            return response()->json([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        return $response;
    }

    public function destroyFile(Request $request, RoleStorageFile $file)
    {
        if (! Schema::hasTable('role_storage_files')) {
            return response()->json([
                'success' => false,
                'message' => 'Role storage is not initialized yet. Please run the database migration.',
            ], 503);
        }

        abort_unless($request->user()->id === $file->user_id, 403, 'You are not allowed to delete this file.');

        if ($file->file_path) {
            $this->evidenceStorage->delete($file->file_path);
        }

        $file->delete();

        return response()->json([
            'success' => true,
            'message' => 'File deleted successfully.',
        ], 200);
    }

    protected function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        $normalized = str_replace(['_', ' '], '-', $normalized);

        if ($normalized === 'program-chair' || $normalized === 'programchair') {
            return 'program-chair';
        }

        return $normalized;
    }

    protected function mimeMatches(string $type): array
    {
        return match ($type) {
            'document', 'doc' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'text/plain', 'text/csv'],
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'video' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo'],
            'audio' => ['audio/mpeg', 'audio/wav', 'audio/x-m4a'],
            'archive' => ['application/zip'],
            default => [],
        };
    }

    protected function wouldCreateCircularReference(RoleStorageFolder $folder, int $newParentId): bool
    {
        $currentParentId = $newParentId;

        while ($currentParentId) {
            if ((int) $currentParentId === (int) $folder->id) {
                return true;
            }

            $parent = RoleStorageFolder::find($currentParentId);
            $currentParentId = $parent ? $parent->parent_id : null;
        }

        return false;
    }
}
