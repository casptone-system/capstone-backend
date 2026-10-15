<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardOfActionDocumentResource;
use App\Models\BoardOfActionDocument;
use App\Models\Program;
use App\Models\User;
use App\Notifications\BoardOfActionUploadedNotification;
use App\Services\EvidenceStorage;
use App\Support\BoardOfActionRules;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class BoardOfActionController extends Controller
{
    public function __construct(private EvidenceStorage $evidenceStorage) {}

    public function programs(Request $request)
    {
        $this->authorize('viewAny', BoardOfActionDocument::class);

        $search = trim((string) $request->query('search', ''));
        $like = $search === '' ? null : '%'.addcslashes($search, '%_\\').'%';

        $programs = Program::query()
            ->with(['college', 'chairUser', 'boardOfActionDocuments.latestVersion'])
            ->when($like, function ($query) use ($like) {
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhereHas('college', fn ($college) => $college->where('name', 'like', $like));
                });
            })
            ->orderBy('name')
            ->get();

        if ($request->boolean('missing')) {
            $programs = $programs->filter(fn (Program $program) => $program->boardOfActionDocuments->isEmpty())->values();
        }

        $colleges = $programs
            ->groupBy(fn (Program $program) => (int) ($program->college_id ?? 0))
            ->map(function (Collection $group) use ($request) {
                $college = $group->first()?->college;

                return [
                    'collegeId' => $college?->id,
                    'collegeName' => $college?->name ?: 'Unassigned college',
                    'programs' => $group->map(fn (Program $program) => $this->programPayload($program, $request))->values(),
                ];
            })
            ->sortBy('collegeName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Board of Action programs retrieved successfully.',
            'data' => $colleges,
        ]);
    }

    public function mine(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->isProgramChair() || $user->isQA() || $user->isSuperAdmin()),
            403,
            'You are not allowed to view Board of Action documents.'
        );

        $programs = Program::query()
            ->where('chair_id', $user->id)
            ->with(['boardOfActionDocuments.latestVersion'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Board of Action documents retrieved successfully.',
            'data' => [
                'programs' => $programs->map(fn (Program $program) => $this->programPayload($program, $request))->values(),
            ],
        ]);
    }

    public function show(BoardOfActionDocument $boardOfActionDocument)
    {
        $this->authorize('view', $boardOfActionDocument);
        $boardOfActionDocument->load('latestVersion');

        return response()->json([
            'success' => true,
            'message' => 'Board of Action document retrieved successfully.',
            'data' => new BoardOfActionDocumentResource($boardOfActionDocument),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', BoardOfActionDocument::class);
        $validated = $this->validatePayload($request, true);
        $file = $request->file('file');
        BoardOfActionRules::assertPdf($file);

        $document = BoardOfActionDocument::create([
            'program_id' => $validated['program_id'],
            'title' => $validated['title'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'uploaded_by' => $request->user()->id,
            'current_version' => 1,
        ]);

        try {
            $this->storeVersion($document, $file, $request->user(), 1);
        } catch (\Throwable) {
            $document->delete();

            return response()->json([
                'success' => false,
                'message' => 'The file could not be stored. Please try again.',
                'errors' => [
                    'file' => ['The file could not be stored. Please try again.'],
                ],
            ], 422);
        }

        $document->load('latestVersion', 'program');
        $this->notifyChair($document, $request->user(), false);

        return response()->json([
            'success' => true,
            'message' => 'Board of Action uploaded successfully.',
            'data' => new BoardOfActionDocumentResource($document),
        ], 201);
    }

    public function replace(Request $request, BoardOfActionDocument $boardOfActionDocument)
    {
        $this->authorize('update', $boardOfActionDocument);
        $validated = $this->validatePayload($request, false);
        $file = $request->file('file');
        BoardOfActionRules::assertPdf($file);

        $nextVersion = ((int) $boardOfActionDocument->versions()->max('version')) + 1;
        $updates = ['current_version' => $nextVersion];

        foreach (['title', 'notes'] as $field) {
            if ($request->exists($field)) {
                $updates[$field] = $validated[$field] ?? null;
            }
        }

        $boardOfActionDocument->fill($updates);

        try {
            $this->storeVersion($boardOfActionDocument, $file, $request->user(), $nextVersion);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'The file could not be stored. Please try again.',
                'errors' => [
                    'file' => ['The file could not be stored. Please try again.'],
                ],
            ], 422);
        }

        $boardOfActionDocument->save();
        $boardOfActionDocument->load('latestVersion', 'program');
        $this->notifyChair($boardOfActionDocument, $request->user(), true);

        return response()->json([
            'success' => true,
            'message' => 'Board of Action replaced successfully.',
            'data' => new BoardOfActionDocumentResource($boardOfActionDocument),
        ]);
    }

    public function destroy(BoardOfActionDocument $boardOfActionDocument)
    {
        $this->authorize('delete', $boardOfActionDocument);

        $directory = 'board-of-action/'.$boardOfActionDocument->id;
        $boardOfActionDocument->delete();
        $this->evidenceStorage->deleteDirectory($directory);

        return response()->json([
            'success' => true,
            'message' => 'Board of Action deleted.',
        ]);
    }

    public function download(BoardOfActionDocument $boardOfActionDocument)
    {
        $this->authorize('download', $boardOfActionDocument);

        $version = $boardOfActionDocument->latestVersion()->first();
        abort_unless($version, 404, 'File not found.');

        $response = $this->evidenceStorage->download($version->file_path, $version->original_name);
        abort_if($response === null, 404, 'File not found.');

        return $response;
    }

    public function preview(BoardOfActionDocument $boardOfActionDocument)
    {
        $this->authorize('download', $boardOfActionDocument);

        $version = $boardOfActionDocument->latestVersion()->first();
        abort_unless($version, 404, 'File not found.');

        $response = $this->evidenceStorage->streamInline(
            $version->file_path,
            $version->original_name,
            'application/pdf'
        );
        abort_if($response === null, 404, 'File not found.');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function programPayload(Program $program, Request $request): array
    {
        $documents = $program->boardOfActionDocuments;
        $newest = $documents->first();

        return [
            'id' => $program->id,
            'name' => $program->name,
            'code' => $program->code,
            'chairName' => $program->chair_name,
            'hasChair' => (bool) $program->chair_id,
            'status' => $documents->isEmpty() ? 'missing' : 'on_file',
            'onFileDate' => $newest?->onFileDate(),
            'documentCount' => $documents->count(),
            'documents' => BoardOfActionDocumentResource::collection($documents)->resolve($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        foreach (['title', 'notes'] as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        if (is_string($request->input('title'))) {
            $title = trim($request->input('title'));
            $request->merge(['title' => $title === '' ? null : $title]);
        }

        $rules = [
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'file' => ['required', 'file', 'max:'.BoardOfActionRules::maxKilobytes()],
        ];

        if ($creating) {
            $rules['program_id'] = ['required', 'exists:programs,id'];
        }

        return $request->validate($rules);
    }

    private function storeVersion(BoardOfActionDocument $document, UploadedFile $file, User $uploader, int $version): void
    {
        $originalName = $this->safePdfName($file->getClientOriginalName());
        $directory = "board-of-action/{$document->id}/v{$version}";
        $filePath = $this->evidenceStorage->putFileAs($directory, $file, $originalName);

        $document->versions()->create([
            'version' => $version,
            'file_path' => $filePath,
            'original_name' => $originalName,
            'mime_type' => 'application/pdf',
            'file_size' => (int) $file->getSize(),
            'uploaded_by' => $uploader->id,
        ]);
    }

    private function notifyChair(BoardOfActionDocument $document, User $uploader, bool $replaced): void
    {
        $document->loadMissing('program.chairUser', 'latestVersion');
        $chair = $document->program?->chairUser;

        if (! $chair) {
            return;
        }

        $chair->notify(new BoardOfActionUploadedNotification(
            $document,
            $replaced,
            $uploader->name,
        ));
    }

    private function safePdfName(?string $name): string
    {
        $base = basename(str_replace('\\', '/', (string) $name));
        $base = preg_replace('/[^\w.\- ]+/u', '_', $base) ?: 'board-of-action.pdf';

        if (! str_ends_with(strtolower($base), '.pdf')) {
            $base .= '.pdf';
        }

        return $base;
    }
}
