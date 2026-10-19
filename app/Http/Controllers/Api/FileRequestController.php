<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileRequestResource;
use App\Models\AccreditationArea;
use App\Models\FileRequest;
use App\Models\FileRequestFile;
use App\Models\Program;
use App\Models\User;
use App\Notifications\FileRequestCreatedNotification;
use App\Notifications\FileRequestDeclinedNotification;
use App\Notifications\FileRequestFulfilledNotification;
use App\Notifications\FileRequestReopenedNotification;
use App\Services\EvidenceStorage;
use App\Support\FileRequestRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class FileRequestController extends Controller
{
    public function __construct(private EvidenceStorage $evidenceStorage) {}

    public function programs(Request $request)
    {
        $this->authorize('browsePrograms', FileRequest::class);

        $user = $request->user();
        $key = 'file-request-programs:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many program lookups. Try again in a minute.',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $ownProgramId = (int) $user->chairedProgramId();
        $search = trim((string) $request->query('q', ''));
        $like = $search === '' ? null : '%'.addcslashes($search, '%_\\').'%';

        $programs = Program::query()
            ->with(['college:id,name', 'chairUser:id,name,first_name,middle_name,last_name'])
            ->when($like, function ($query) use ($like) {
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhereHas('college', fn ($college) => $college->where('name', 'like', $like));
                });
            })
            ->where('id', '!=', $ownProgramId)
            ->orderBy('name')
            ->get();

        $colleges = $programs
            ->groupBy(fn (Program $program) => (int) ($program->college_id ?? 0))
            ->map(function (Collection $group) {
                $college = $group->first()?->college;

                return [
                    'collegeId' => $college?->id,
                    'collegeName' => $college?->name ?: 'Unassigned college',
                    'programs' => $group->map(function (Program $program) {
                        $chairName = $program->chair_id ? $program->chairUser?->name : null;
                        $canReceive = $chairName !== null;

                        return [
                            'id' => $program->id,
                            'name' => $program->name,
                            'code' => $program->code,
                            'chairName' => $canReceive ? $chairName : null,
                            'canReceive' => $canReceive,
                            'disabledReason' => $canReceive ? null : 'No chair assigned',
                        ];
                    })->values(),
                ];
            })
            ->sortBy('collegeName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Programs retrieved successfully.',
            'data' => $colleges,
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', FileRequest::class);

        $user = $request->user();
        $query = FileRequest::query()->withParties()->latest('id');
        $pendingIncomingCount = null;

        if ($this->oversees($user)) {
            // Oversight sees every request. Chairs stay limited to their program below.
        } else {
            $programId = (int) $user->chairedProgramId();
            $box = (string) $request->query('box', 'incoming');
            if (! in_array($box, ['incoming', 'sent'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose incoming or sent requests.',
                ], 422);
            }

            if ($box === 'incoming') {
                $query->where('recipient_program_id', $programId);
            } else {
                $query->where('requester_program_id', $programId);
            }

            $pendingIncomingCount = FileRequest::query()
                ->where('recipient_program_id', $programId)
                ->where('status', FileRequest::STATUS_PENDING)
                ->count();
        }

        $page = $query->paginate(min((int) $request->query('per_page', 50), 50));

        return response()->json([
            'success' => true,
            'message' => 'File requests retrieved successfully.',
            'data' => FileRequestResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'pendingIncomingCount' => $pendingIncomingCount,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', FileRequest::class);

        $user = $request->user();
        $requesterProgramId = (int) $user->chairedProgramId();

        $validated = $request->validate([
            'recipient_program_id' => ['required', 'integer', 'exists:programs,id'],
            'title' => ['required', 'string', 'max:'.FileRequestRules::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.FileRequestRules::DESCRIPTION_MAX],
            'needed_by' => ['nullable', 'date'],
            'related_area_id' => ['nullable', 'integer', 'exists:accreditation_areas,id'],
        ]);

        $recipient = Program::query()->findOrFail($validated['recipient_program_id']);
        $title = trim((string) preg_replace('/\s+/u', ' ', $validated['title']));

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'A title is required.',
            ]);
        }

        if ((int) $recipient->id === $requesterProgramId) {
            throw ValidationException::withMessages([
                'recipient_program_id' => 'A program cannot request a file from itself.',
            ]);
        }

        if (! $recipient->chair_id || ! User::query()->whereKey($recipient->chair_id)->exists()) {
            throw ValidationException::withMessages([
                'recipient_program_id' => 'This program has no chair assigned.',
            ]);
        }

        if (! empty($validated['related_area_id'])) {
            $belongs = AccreditationArea::query()
                ->whereKey($validated['related_area_id'])
                ->whereHas('cycle', fn ($cycle) => $cycle->where('program_id', $requesterProgramId))
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'related_area_id' => 'The related area must belong to your program.',
                ]);
            }
        }

        $openKey = FileRequestRules::openKey($requesterProgramId, (int) $recipient->id, $title);
        $startOfDay = now('Asia/Manila')->startOfDay();

        try {
            $fileRequest = DB::transaction(function () use ($user, $requesterProgramId, $recipient, $validated, $title, $openKey, $startOfDay) {
                $createdToday = FileRequest::query()
                    ->where('requester_user_id', $user->id)
                    ->where('created_at', '>=', $startOfDay)
                    ->lockForUpdate()
                    ->count();

                if ($createdToday >= FileRequestRules::DAILY_LIMIT) {
                    throw ValidationException::withMessages([
                        'title' => 'You can create up to '.FileRequestRules::DAILY_LIMIT.' file requests per day.',
                    ]);
                }

                $duplicate = FileRequest::query()->where('open_key', $openKey)->lockForUpdate()->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'title' => 'An open request with this title already exists for that program.',
                    ]);
                }

                return FileRequest::create([
                    'requester_program_id' => $requesterProgramId,
                    'requester_user_id' => $user->id,
                    'recipient_program_id' => $recipient->id,
                    'recipient_user_id' => $recipient->chair_id,
                    'title' => $title,
                    'description' => $this->blankToNull($validated['description'] ?? null),
                    'needed_by' => $validated['needed_by'] ?? null,
                    'related_area_id' => $validated['related_area_id'] ?? null,
                    'status' => FileRequest::STATUS_PENDING,
                    'open_key' => $openKey,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConflict($exception)) {
                throw ValidationException::withMessages([
                    'title' => 'An open request with this title already exists for that program.',
                ]);
            }

            throw $exception;
        }

        $fileRequest = FileRequest::query()->withParties()->findOrFail($fileRequest->id);
        $this->notifyProgramChair($fileRequest->recipientProgram, new FileRequestCreatedNotification($fileRequest));
        $request->attributes->set('audit_event', 'file_request_created');

        return response()->json([
            'success' => true,
            'message' => 'File request sent.',
            'data' => new FileRequestResource($fileRequest),
        ], 201);
    }

    public function cancel(Request $request, FileRequest $fileRequest)
    {
        $this->authorize('cancel', $fileRequest);

        $fileRequest->update([
            'status' => FileRequest::STATUS_CANCELLED,
            'open_key' => null,
        ]);
        $request->attributes->set('audit_event', 'file_request_cancelled');

        return response()->json([
            'success' => true,
            'message' => 'File request cancelled.',
            'data' => new FileRequestResource(FileRequest::query()->withParties()->findOrFail($fileRequest->id)),
        ]);
    }

    public function decline(Request $request, FileRequest $fileRequest)
    {
        $this->authorize('decline', $fileRequest);

        $validated = $request->validate([
            'decline_reason' => ['required', 'string', 'max:'.FileRequestRules::DECLINE_REASON_MAX],
        ]);

        $fileRequest->update([
            'status' => FileRequest::STATUS_DECLINED,
            'decline_reason' => trim($validated['decline_reason']),
            'open_key' => null,
        ]);

        $fileRequest = FileRequest::query()->withParties()->findOrFail($fileRequest->id);
        $this->notifyProgramChair($fileRequest->requesterProgram, new FileRequestDeclinedNotification($fileRequest));
        $request->attributes->set('audit_event', 'file_request_declined');

        return response()->json([
            'success' => true,
            'message' => 'File request declined.',
            'data' => new FileRequestResource($fileRequest),
        ]);
    }

    public function fulfill(Request $request, FileRequest $fileRequest)
    {
        $this->authorize('fulfill', $fileRequest);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.FileRequestRules::MAX_FILES],
            'files.*' => ['file', 'max:'.FileRequestRules::maxKilobytes()],
        ], [
            'files.max' => 'A request can include up to '.FileRequestRules::MAX_FILES.' PDF files.',
        ]);

        /** @var array<int, UploadedFile> $uploads */
        $uploads = $request->file('files', []);
        foreach ($uploads as $upload) {
            FileRequestRules::assertPdf($upload);
        }

        $storedPaths = [];

        try {
            DB::transaction(function () use ($fileRequest, $uploads, $request, &$storedPaths) {
                $locked = FileRequest::query()->whereKey($fileRequest->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isPending()) {
                    throw ValidationException::withMessages([
                        'files' => 'This request is no longer pending.',
                    ]);
                }

                foreach ($uploads as $upload) {
                    $path = $this->evidenceStorage->store('file-requests/'.$locked->id, $upload);
                    $storedPaths[] = $path;
                    FileRequestFile::create([
                        'file_request_id' => $locked->id,
                        'file_path' => $path,
                        'original_name' => $upload->getClientOriginalName(),
                        'mime_type' => $upload->getMimeType() ?: 'application/pdf',
                        'file_size' => (int) $upload->getSize(),
                        'uploaded_by' => $request->user()->id,
                    ]);
                }

                $locked->update([
                    'status' => FileRequest::STATUS_FULFILLED,
                    'fulfilled_at' => now(),
                    'open_key' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->evidenceStorage->delete($path);
            }

            if ($exception instanceof ValidationException) {
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

        $fileRequest = FileRequest::query()->withParties()->findOrFail($fileRequest->id);
        $this->notifyProgramChair($fileRequest->requesterProgram, new FileRequestFulfilledNotification($fileRequest));
        $request->attributes->set('audit_event', 'file_request_fulfilled');

        return response()->json([
            'success' => true,
            'message' => 'File request fulfilled.',
            'data' => new FileRequestResource($fileRequest),
        ]);
    }

    public function destroyFile(Request $request, FileRequest $fileRequest, FileRequestFile $fileRequestFile)
    {
        $this->authorize('removeFile', $fileRequest);
        abort_unless((int) $fileRequestFile->file_request_id === (int) $fileRequest->id, 404);

        $path = $fileRequestFile->file_path;
        $reopened = false;

        DB::transaction(function () use ($fileRequest, $fileRequestFile, &$reopened) {
            $fileRequestFile->delete();
            $remaining = $fileRequest->files()->count();
            if ($remaining > 0) {
                return;
            }

            $openKey = FileRequestRules::openKey(
                (int) $fileRequest->requester_program_id,
                (int) $fileRequest->recipient_program_id,
                (string) $fileRequest->title
            );
            $taken = FileRequest::query()->where('open_key', $openKey)->lockForUpdate()->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'file' => 'Another open request already uses this title, so the last file cannot be removed yet.',
                ]);
            }

            $fileRequest->update([
                'status' => FileRequest::STATUS_PENDING,
                'fulfilled_at' => null,
                'open_key' => $openKey,
            ]);
            $reopened = true;
        });

        $this->evidenceStorage->delete($path);
        $request->attributes->set('audit_event', 'file_request_file_removed');

        $fileRequest = FileRequest::query()->withParties()->findOrFail($fileRequest->id);
        if ($reopened) {
            $this->notifyProgramChair($fileRequest->requesterProgram, new FileRequestReopenedNotification($fileRequest));
        }

        return response()->json([
            'success' => true,
            'message' => $reopened ? 'The last file was removed. The request is pending again.' : 'File removed.',
            'data' => new FileRequestResource($fileRequest),
        ]);
    }

    public function download(Request $request, FileRequest $fileRequest, FileRequestFile $fileRequestFile)
    {
        $this->authorize('download', $fileRequest);
        abort_unless((int) $fileRequestFile->file_request_id === (int) $fileRequest->id, 404);

        $response = $this->evidenceStorage->respond(
            $request,
            $fileRequestFile->file_path,
            $fileRequestFile->original_name,
            'attachment',
            'application/pdf'
        );
        abort_if($response === null, 404, 'File not found.');

        $request->attributes->set('audit_event', 'file_request_downloaded');

        return $response;
    }

    public function preview(Request $request, FileRequest $fileRequest, FileRequestFile $fileRequestFile)
    {
        $this->authorize('download', $fileRequest);
        abort_unless((int) $fileRequestFile->file_request_id === (int) $fileRequest->id, 404);

        $response = $this->evidenceStorage->respond(
            $request,
            $fileRequestFile->file_path,
            $fileRequestFile->original_name,
            'inline',
            'application/pdf'
        );
        abort_if($response === null, 404, 'File not found.');

        $request->attributes->set('audit_event', 'file_request_previewed');

        return $response;
    }

    private function oversees(User $user): bool
    {
        return $user->isQA() || $user->isVPAA() || $user->isSuperAdmin();
    }

    private function notifyProgramChair(?Program $program, \Illuminate\Notifications\Notification $notification): void
    {
        $chairId = $program?->chair_id;
        if (! $chairId) {
            return;
        }

        $chair = User::query()->find($chairId);
        $chair?->notify($notification);
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
