<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoleStorageFile;
use App\Models\RoleStorageFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RoleStorageController extends Controller
{
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

        $folders = RoleStorageFolder::with(['files' => fn ($query) => $query->orderBy('created_at', 'desc')])
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $folders,
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

        $folder = RoleStorageFolder::create([
            'user_id' => $request->user()->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'role' => $this->normalizeRole($validated['role']),
            'name' => trim($validated['name']),
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
            'file' => ['required', 'file', 'max:51200'],
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $safeName = time() . '-' . Str::uuid()->toString() . '-' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
        $relativePath = 'role-storage/' . $user->id . '/' . $folder->role . '/' . $folder->id . '/' . $safeName;

        $storedPath = Storage::disk('local')->putFileAs(
            'role-storage/' . $user->id . '/' . $folder->role . '/' . $folder->id,
            $file,
            $safeName
        );

        $storageFile = RoleStorageFile::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'role' => $folder->role,
            'name' => $originalName,
            'original_name' => $originalName,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_path' => $storedPath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully.',
            'data' => $storageFile,
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

        if (! Storage::disk('local')->exists($file->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        $mimeType = $file->mime_type ?: Storage::disk('local')->mimeType($file->file_path);

        return response()->stream(function () use ($file) {
            echo Storage::disk('local')->get($file->file_path);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $file->original_name . '"',
            'Content-Length' => Storage::disk('local')->size($file->file_path),
            'Cache-Control' => 'private, no-transform',
        ]);
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

        if ($file->file_path && Storage::disk('local')->exists($file->file_path)) {
            Storage::disk('local')->delete($file->file_path);
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
}
