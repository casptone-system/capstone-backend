<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentVersionResource;
use App\Models\AccreditationArea;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentUploadedNotification;
use App\Services\AreaProgressService;
use App\Services\EvidenceStorage;
use App\Support\AreaDocumentRules;
use App\Support\AreaEvidenceGate;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(private EvidenceStorage $evidenceStorage)
    {
    }
    /**
     * Display a paginated list of documents.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Document::with('program', 'area', 'task', 'uploader', 'versions');

        // Scope documents returned based on policy-like rules to avoid IDOR
        if (! $user->isSuperAdmin() && ! $user->isQA() && ! $user->isVPAA()) {
            if ($user->isProgramChair()) {
                $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();
                if ($programId) {
                    $query->forProgram((int) $programId);
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->isDean()) {
                $collegeId = $user->college_id;
                if ($collegeId) {
                    $query->whereHas('program', fn ($q) => $q->where('college_id', $collegeId));
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->isAreaIncharge()) {
                $areaIds = $user->assignedAreaIds()->toArray();
                $query->where(function ($scoped) use ($areaIds) {
                    $scoped->whereIn('area_id', $areaIds)
                        ->orWhereHas('contentRow.parameter', fn ($parameter) => $parameter->whereIn('area_id', $areaIds));
                });
            } elseif ($user->isFaculty()) {
                $areaIds = $user->assignedAreaIds()->all();
                $query->where(function ($scoped) use ($user, $areaIds) {
                    $scoped->where('uploaded_by', $user->id);
                    if ($areaIds !== []) {
                        $scoped->orWhereIn('area_id', $areaIds)
                            ->orWhereHas('contentRow.parameter', fn ($parameter) => $parameter->whereIn('area_id', $areaIds));
                    }
                });
            } else {
                $query->where('uploaded_by', $user->id);
            }
        }

        if ($request->filled('program_id')) {
            $query->forProgram((int) $request->program_id);
        }

        if ($request->filled('area_id')) {
            $query->forArea((int) $request->area_id);
        }

        if ($request->filled('task_id')) {
            $query->where('task_id', $request->task_id);
        }

        if ($request->filled('content_row_id')) {
            $query->where('content_row_id', $request->content_row_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('school_year')) {
            $query->where('school_year', $request->school_year);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        $documents = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Documents retrieved successfully.',
            'data' => DocumentResource::collection($documents->getCollection())->toArray($request),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ], 200);
    }

    /**
     * Store a newly created document with file upload.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Document::class);

        $validated = $request->validate([
            'program_id' => ['required', 'exists:programs,id'],
            'area_id' => ['nullable', 'exists:accreditation_areas,id'],
            'task_id' => ['nullable', 'exists:tasks,id'],
            'content_row_id' => ['nullable', 'exists:parameter_content_rows,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'school_year' => ['nullable', 'string', 'max:20'],
            'file' => ['required', 'file', 'max:'.$this->evidenceStorage->documentUploadMaxKilobytes()],
        ]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $fileSize = $file->getSize();

        $area = AreaEvidenceGate::resolveArea(
            isset($validated['area_id']) ? (int) $validated['area_id'] : null,
            isset($validated['content_row_id']) ? (int) $validated['content_row_id'] : null
        );

        if ($area) {
            AreaEvidenceGate::assertCanUpload($request->user(), $area);
            AreaDocumentRules::assertPdfUpload($file);
            AreaDocumentRules::assertRowHasCapacity(
                isset($validated['content_row_id']) ? (int) $validated['content_row_id'] : null
            );
            $validated['area_id'] = $area->id;
            $cycleProgramId = $area->cycle()->value('program_id');
            if ($cycleProgramId && (int) $cycleProgramId !== (int) $validated['program_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected area does not belong to the selected program.',
                ], 422);
            }
        }

        // Prepare document data (do not store the uploaded file path on the documents table)
        $documentData = collect($validated)->except(['file'])->toArray();
        $documentData['uploaded_by'] = $request->user()->id;
        $documentData['current_version'] = 1;
        $documentData['status'] = 'Active';

        $document = Document::create($documentData);

        // Store the file
        $versionPath = "documents/{$document->id}/v1";
        $filePath = $this->evidenceStorage->putFileAs($versionPath, $file, $originalName);

        // Create the version record
        $document->versions()->create([
            'version' => 1,
            'file_path' => $filePath,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'uploaded_by' => $request->user()->id,
        ]);

        // Send Document Uploaded notification to area chair and members
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

    /**
     * Display the specified document.
     */
    public function show(Document $document)
    {
        $this->authorize('view', $document);

        $document->load('program', 'area', 'task', 'uploader', 'versions.uploader');

        return response()->json([
            'success' => true,
            'message' => 'Document retrieved successfully.',
            'data' => new DocumentResource($document),
        ], 200);
    }

    /**
     * Update the specified document's metadata.
     */
    public function update(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'school_year' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:' . implode(',', Document::STATUSES)],
        ]);

        $document->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Document updated successfully.',
            'data' => new DocumentResource($document->load('program', 'area', 'task', 'uploader', 'versions')),
        ], 200);
    }

    /**
     * Remove the specified document and its files.
     */
    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);

        $contentRowId = $document->content_row_id;

        // Delete all stored files
        $documentPath = "documents/{$document->id}";
        $this->evidenceStorage->deleteDirectory($documentPath);

        $document->delete();

        if ($contentRowId) {
            app(AreaProgressService::class)->refreshForContentRow((int) $contentRowId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully.',
        ], 200);
    }

    /**
     * Replace the document with a new version.
     */
    public function replace(Request $request, Document $document)
    {
        $this->authorize('replace', $document);

        if ($document->area_id || $document->content_row_id) {
            $area = $document->area ?: AreaEvidenceGate::resolveArea($document->area_id, $document->content_row_id);
            AreaEvidenceGate::assertCanUpload($request->user(), $area);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->evidenceStorage->documentUploadMaxKilobytes()],
        ]);

        $file = $request->file('file');
        if ($document->area_id || $document->content_row_id) {
            AreaDocumentRules::assertPdfUpload($file);
        }
        $mimeType = $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $fileSize = $file->getSize();

        $newVersion = $document->current_version + 1;

        // Store the new file
        $versionPath = "documents/{$document->id}/v{$newVersion}";
        $filePath = $this->evidenceStorage->putFileAs($versionPath, $file, $originalName);

        // Create the version record
        $document->versions()->create([
            'version' => $newVersion,
            'file_path' => $filePath,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'uploaded_by' => $request->user()->id,
        ]);

        // Update the document's current version
        $document->update(['current_version' => $newVersion]);

        return response()->json([
            'success' => true,
            'message' => 'Document replaced successfully.',
            'data' => new DocumentResource($document->load('program', 'area', 'task', 'uploader', 'versions')),
        ], 200);
    }

    /**
     * Get the version history of a document.
     */
    public function versions(Document $document)
    {
        $this->authorize('view', $document);

        $versions = $document->versions()->with('uploader')->orderBy('version', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Document versions retrieved successfully.',
            'data' => DocumentVersionResource::collection($versions),
        ], 200);
    }

    /**
     * Download the specified version of a document.
     * If no version specified, download the latest.
     */
    public function download(Request $request, Document $document)
    {
        $this->authorize('download', $document);

        $versionNumber = $request->get('version', $document->current_version);

        $version = $document->versions()->where('version', $versionNumber)->firstOrFail();

        if (! $this->evidenceStorage->exists($version->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        return $this->evidenceStorage->download($version->file_path, $version->original_name);
    }

    /**
     * Stream a previewable version inline (PDF, image, audio, video).
     */
    public function preview(Request $request, Document $document)
    {
        $this->authorize('download', $document);

        $versionNumber = $request->get('version', $document->current_version);
        $version = $document->versions()->where('version', $versionNumber)->firstOrFail();

        if (! $this->isPreviewable($version->mime_type, $version->original_name)) {
            return response()->json([
                'success' => false,
                'message' => 'This file type cannot be previewed in the browser.',
            ], 415);
        }

        $response = $this->evidenceStorage->streamInline(
            $version->file_path,
            $version->original_name,
            $version->mime_type
        );

        if ($response === null) {
            return response()->json([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        return $response;
    }

    private function isPreviewable(?string $mimeType, ?string $fileName): bool
    {
        $mime = strtolower((string) $mimeType);
        $extension = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || $mime === 'application/pdf'
            || in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'webm', 'mp3', 'wav', 'm4a'], true);
    }

    /**
     * Notify area chair and members about a newly uploaded document.
     * Excludes the uploader from receiving the notification.
     */
    private function notifyDocumentUploaded(Document $document, User $uploader): void
    {
        $recipientIds = collect();

        // Get area chair and members if the document belongs to an area
        if ($document->area_id) {
            $area = AccreditationArea::with('chair', 'members.user')->find($document->area_id);

            if ($area) {
                // Add area chair
                if ($area->chair_id && $area->chair_id !== $uploader->id) {
                    $recipientIds->push($area->chair_id);
                }

                // Add area members
                foreach ($area->members as $member) {
                    if ($member->user_id && $member->user_id !== $uploader->id) {
                        $recipientIds->push($member->user_id);
                    }
                }
            }
        }

        // Get task assignees if the document belongs to a task
        if ($document->task_id) {
            $document->load('task.assignments.user');
            foreach ($document->task->assignments as $assignment) {
                if ($assignment->user_id && $assignment->user_id !== $uploader->id) {
                    $recipientIds->push($assignment->user_id);
                }
            }
        }

        // Send notifications to unique recipients
        $recipientIds = $recipientIds->unique();

        if ($recipientIds->isNotEmpty()) {
            $users = User::whereIn('id', $recipientIds)->get();
            $document->load('uploader');

            foreach ($users as $user) {
                $user->notify(new DocumentUploadedNotification($document, $uploader->name));
            }
        }
    }
}
