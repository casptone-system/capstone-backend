<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UniversalFileResource;
use App\Models\Program;
use App\Models\UniversalFile;
use App\Models\User;
use App\Notifications\UniversalFileUploadedNotification;
use App\Services\EvidenceStorage;
use App\Support\UniversalFileRules;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class UniversalFileController extends Controller
{
    public function __construct(private EvidenceStorage $evidenceStorage) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', UniversalFile::class);

        $files = UniversalFile::query()
            ->with('uploader:id,name,first_name,middle_name,last_name')
            ->orderBy('area_code')
            ->latest('id')
            ->limit(300)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Universal files retrieved successfully.',
            'data' => [
                'areas' => UniversalFileRules::areas(),
                'files' => UniversalFileResource::collection($files)->resolve($request),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', UniversalFile::class);

        $request->validate([
            'area_code' => ['required', 'string', Rule::in(UniversalFileRules::codes())],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'files' => ['required', 'array', 'min:1', 'max:'.UniversalFileRules::MAX_FILES],
            'files.*' => ['file', 'max:'.UniversalFileRules::maxKilobytes()],
        ], [
            'area_code.required' => 'Choose the area these files belong to.',
            'area_code.in' => 'Choose one of the accreditation areas, or General.',
            'files.max' => 'Upload up to '.UniversalFileRules::MAX_FILES.' PDF files at a time.',
        ]);

        /** @var array<int, UploadedFile> $uploads */
        $uploads = $request->file('files', []);
        foreach ($uploads as $upload) {
            UniversalFileRules::assertPdf($upload);
        }

        $areaCode = (string) $request->input('area_code');
        $title = trim((string) $request->input('title', ''));
        $notes = trim((string) $request->input('notes', ''));
        $created = [];
        $paths = [];

        try {
            foreach ($uploads as $upload) {
                $name = $upload->getClientOriginalName();
                $record = UniversalFile::create([
                    'title' => count($uploads) === 1 && $title !== '' ? $title : $name,
                    'notes' => $notes !== '' ? $notes : null,
                    'area_code' => $areaCode,
                    'file_path' => 'pending',
                    'original_name' => $name,
                    'mime_type' => $upload->getMimeType() ?: 'application/pdf',
                    'file_size' => (int) $upload->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);
                $created[] = $record;
                $path = $this->evidenceStorage->store('universal-files/'.$record->id, $upload);
                $paths[] = $path;
                $record->update(['file_path' => $path]);
            }
        } catch (\Throwable $exception) {
            foreach ($created as $index => $record) {
                if (isset($paths[$index])) {
                    $this->evidenceStorage->delete($paths[$index]);
                }
                $record->delete();
            }

            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }

            return response()->json([
                'success' => false,
                'message' => 'The file could not be stored. Please try again.',
                'errors' => [
                    'files' => ['The file could not be stored. Please try again.'],
                ],
            ], 422);
        }

        $this->notifyChairs(collect($created));
        $request->attributes->set('audit_event', 'universal_file_uploaded');

        $fresh = UniversalFile::query()->with('uploader:id,name,first_name,middle_name,last_name')
            ->whereIn('id', collect($created)->pluck('id'))
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Universal files uploaded.',
            'data' => UniversalFileResource::collection($fresh)->resolve($request),
        ], 201);
    }

    public function destroy(Request $request, UniversalFile $universalFile)
    {
        $this->authorize('delete', $universalFile);

        $path = $universalFile->file_path;
        $universalFile->delete();
        if ($path !== '') {
            $this->evidenceStorage->delete($path);
        }
        $request->attributes->set('audit_event', 'universal_file_deleted');

        return response()->json([
            'success' => true,
            'message' => 'Universal file removed.',
        ]);
    }

    public function download(Request $request, UniversalFile $universalFile)
    {
        $this->authorize('download', $universalFile);

        $response = $this->evidenceStorage->download($universalFile->file_path, $universalFile->original_name);
        abort_if($response === null, 404, 'File not found.');
        $request->attributes->set('audit_event', 'universal_file_downloaded');

        return $response;
    }

    public function preview(Request $request, UniversalFile $universalFile)
    {
        $this->authorize('download', $universalFile);

        $response = $this->evidenceStorage->streamInline(
            $universalFile->file_path,
            $universalFile->original_name,
            'application/pdf'
        );
        abort_if($response === null, 404, 'File not found.');
        $request->attributes->set('audit_event', 'universal_file_previewed');

        return $response;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, UniversalFile>  $files
     */
    private function notifyChairs($files): void
    {
        if ($files->isEmpty()) {
            return;
        }

        $chairIds = Program::query()->whereNotNull('chair_id')->distinct()->pluck('chair_id');
        $chairs = User::query()->whereIn('id', $chairIds)->get();
        if ($chairs->isEmpty()) {
            return;
        }

        Notification::send($chairs, new UniversalFileUploadedNotification($files));
    }
}
