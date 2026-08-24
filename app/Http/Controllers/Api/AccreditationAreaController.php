<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationAreaResource;
use App\Http\Resources\AreaMemberResource;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AreaMember;
use App\Models\Program;
use App\Models\Review;
use App\Models\User;
use App\Notifications\AreaInChargeAssignedNotification;
use App\Services\AreaProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccreditationAreaController extends Controller
{
    /**
     * Display a paginated list of accreditation areas for a cycle.
     */
    public function index(Request $request)
    {
        $query = AccreditationArea::with('chair', 'members.user');

        if ($request->filled('cycle_id')) {
            $this->assertCanViewCycle($request->user(), AccreditationCycle::findOrFail($request->cycle_id));
            $query->where('cycle_id', $request->cycle_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        $areas = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Accreditation areas retrieved successfully.',
            'data' => AccreditationAreaResource::collection($areas),
        ], 200);
    }

    /**
     * Store a newly created accreditation area.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cycle_id' => ['required', 'exists:accreditation_cycles,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'chair_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:' . implode(',', AccreditationArea::STATUSES)],
        ]);

        $area = AccreditationArea::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area created successfully.',
            'data' => new AccreditationAreaResource($area->load('chair', 'members.user')),
        ], 201);
    }

    /**
     * Display the specified accreditation area (Area Details).
     */
    public function show(AccreditationArea $accreditationArea)
    {
        $this->assertCanViewCycle(request()->user(), $accreditationArea->cycle()->firstOrFail());
        $accreditationArea->load('chair', 'members.user', 'cycle.program');

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area retrieved successfully.',
            'data' => new AccreditationAreaResource($accreditationArea),
        ], 200);
    }

    /**
     * Update the specified accreditation area.
     */
    public function update(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanManageCycle($request->user(), $accreditationArea->cycle()->firstOrFail());
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'chair_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:' . implode(',', AccreditationArea::STATUSES)],
        ]);

        $accreditationArea->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area updated successfully.',
            'data' => new AccreditationAreaResource($accreditationArea->load('chair', 'members.user')),
        ], 200);
    }

    /**
     * Remove the specified accreditation area.
     */
    public function destroy(AccreditationArea $accreditationArea)
    {
        $this->assertCanManageCycle(request()->user(), $accreditationArea->cycle()->firstOrFail());
        $accreditationArea->delete();

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area deleted successfully.',
        ], 200);
    }

    /**
     * Assign a chair to the accreditation area.
     *
     * Assigning a new chair replaces the previous chair for this area
     * (no stacking). The chosen chair is also removed from the member list
     * so a user cannot be both Area Chair and Member of the same area.
     */
    public function assignChair(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanManageCycle($request->user(), $accreditationArea->cycle()->firstOrFail());
        $validated = $request->validate([
            'chair_id' => ['required', 'exists:users,id'],
            'confirm_reassign' => ['sometimes', 'boolean'],
        ]);

        $accreditationArea->load('chair');
        $newChairId = (int) $validated['chair_id'];
        $currentChairId = $accreditationArea->chair_id ? (int) $accreditationArea->chair_id : null;

        if ($currentChairId && $currentChairId !== $newChairId && ! $request->boolean('confirm_reassign')) {
            return response()->json([
                'success' => false,
                'message' => 'This area already has an Area In-Charge. Confirm to reassign.',
                'data' => [
                    'requiresConfirmation' => true,
                    'currentChair' => $accreditationArea->chair ? [
                        'id' => $accreditationArea->chair->id,
                        'name' => $accreditationArea->chair->name,
                        'email' => $accreditationArea->chair->email,
                    ] : null,
                ],
            ], 409);
        }

        $assignee = User::findOrFail($newChairId);

        $accreditationArea->members()->where('user_id', $newChairId)->delete();
        $accreditationArea->update(['chair_id' => $newChairId]);

        if (! $assignee->isAreaIncharge()) {
            $assignee->assignRole('Area In-Charge');
        }

        if ($currentChairId !== $newChairId) {
            $assignee->notify(new AreaInChargeAssignedNotification(
                $accreditationArea->fresh(['cycle.program'])
            ));
        }

        app(AreaProgressService::class)->refresh($accreditationArea->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Area Chair assigned successfully.',
            'data' => new AccreditationAreaResource($accreditationArea->load('chair', 'members.user')),
        ], 200);
    }

    /**
     * Get all members of the accreditation area.
     */
    public function getMembers(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanViewCycle($request->user(), $accreditationArea->cycle()->firstOrFail());
        $members = $accreditationArea->members()->with('user')->get();

        return response()->json([
            'success' => true,
            'message' => 'Members retrieved successfully.',
            'data' => AreaMemberResource::collection($members),
        ], 200);
    }

    /**
     * Add a member to the accreditation area.
     */
    public function addMember(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanManageCycle($request->user(), $accreditationArea->cycle()->firstOrFail());

        $userId = $request->input('user_id', $request->input('faculty_id'));
        $validated = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'faculty_id' => ['sometimes', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
        ]);

        $resolvedUserId = $validated['user_id'] ?? $validated['faculty_id'] ?? $userId;
        if (empty($resolvedUserId)) {
            return response()->json([
                'success' => false,
                'message' => 'The faculty is required.',
            ], 422);
        }

        $member = $accreditationArea->members()->create([
            'user_id' => $resolvedUserId,
            'role' => $validated['role'] ?? 'member',
        ]);

        $member->load('user');

        // Send notification to faculty
        $faculty = $member->user;
        if ($faculty) {
            $programChair = $request->user();
            $faculty->notify(new \App\Notifications\FacultyAreaAssignmentNotification([
                'program_chair_name' => $programChair->name,
                'program_name' => $accreditationArea->cycle->program->name,
                'area_name' => $accreditationArea->name,
                'deadline' => $validated['deadline'] ?? now()->addDays(30)->format('Y-m-d'),
                'instructions' => $validated['instructions'] ?? '',
            ]));

            // Log activity
            \App\Models\AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'assign_faculty_to_area',
                'model' => 'AccreditationArea',
                'model_id' => $accreditationArea->id,
                'details' => [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $faculty->name,
                    'area_name' => $accreditationArea->name,
                    'role' => $validated['role'] ?? 'member',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Member added successfully. Faculty has been notified.',
            'data' => new AreaMemberResource($member),
        ], 201);
    }

    /**
     * Remove a member from the accreditation area.
     */
    public function removeMember(AccreditationArea $accreditationArea, AreaMember $member)
    {
        if ($member->area_id !== $accreditationArea->id) {
            return response()->json([
                'success' => false,
                'message' => 'Member does not belong to this area.',
            ], 404);
        }

        $member->delete();

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully.',
        ], 200);
    }

    /**
     * Get the progress of an accreditation area.
     */
    public function progress(AccreditationArea $accreditationArea)
    {
        $this->assertCanViewCycle(request()->user(), $accreditationArea->cycle()->firstOrFail());
        $accreditationArea->load('chair', 'members.user');

        $totalMembers = $accreditationArea->members->count();
        $status = $accreditationArea->status;

        return response()->json([
            'success' => true,
            'message' => 'Area progress retrieved successfully.',
            'data' => [
                'area' => new AccreditationAreaResource($accreditationArea),
                'progress' => [
                    'status' => $status,
                    'totalMembers' => $totalMembers,
                    'hasChair' => $accreditationArea->chair_id !== null,
                ],
            ],
        ], 200);
    }

    /**
     * Submit files and evidence for an accreditation area by faculty
     */
    public function submitFiles(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'area_id' => 'required|exists:accreditation_areas,id',
            'files' => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx|max:10240',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $area = AccreditationArea::findOrFail($validated['area_id']);

            // Verify faculty is assigned to this area
            if (! $area->members()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not assigned to this area.',
                ], 403);
            }

            // Store uploaded files
            $storedFiles = [];
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $path = $file->store("accreditation/area-{$area->id}/submissions", 'private');
                    $storedFiles[] = [
                        'original_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $user->id,
                        'uploaded_at' => now(),
                    ];
                }
            }

            // Create submission record
            $submission = DB::table('area_submissions')->insert([
                'area_id' => $area->id,
                'user_id' => $user->id,
                'notes' => $validated['notes'],
                'status' => 'Pending Review',
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Store individual files
            foreach ($storedFiles as $file) {
                DB::table('area_submission_files')->insert($file);
            }

            // Notify program chair
            $programChair = $area->cycle->program->chairUser;
            if ($programChair) {
                $programChair->notify(new \App\Notifications\FacultySubmissionNotification([
                    'faculty_name' => $user->name,
                    'area_name' => $area->name,
                    'program_name' => $area->cycle->program->name,
                    'file_count' => count($storedFiles),
                    'submitted_at' => now()->toDateTimeString(),
                ]));
            }

            // Log activity
            \App\Models\AuditLog::create([
                'user_id' => $user->id,
                'action' => 'submit_area_files',
                'model' => 'AccreditationArea',
                'model_id' => $area->id,
                'details' => [
                    'area_name' => $area->name,
                    'file_count' => count($storedFiles),
                    'notes' => substr($validated['notes'] ?? '', 0, 100),
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Files submitted successfully. Program Chair will review shortly.',
                'data' => [
                    'area_id' => $area->id,
                    'submitted_at' => now()->toIso8601String(),
                    'file_count' => count($storedFiles),
                    'status' => 'Pending Review',
                ],
                'submission' => true,
            ], 200);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to submit area files', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'area_id' => $validated['area_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit files. Please try again.',
            ], 500);
        }
    }

    /**
     * List Level I–IV folders for the authenticated Program Chair, with the
     * 10 fixed AACCUP areas under each existing accreditation cycle.
     *
     * Seeding is idempotent per cycle. Levels without a cycle are returned
     * empty so all four levels stay browsable.
     */
    public function programChairAreas(Request $request)
    {
        $user = $request->user() ?? $request->user('api');
        $program = $this->resolveVisibleProgram($request, $user, 'You need a program assigned before managing area assignments.');
        $cyclesByLevel = $program->accreditationCycles
            ->sortByDesc('created_at')
            ->unique('level')
            ->values();

        $levels = collect(AccreditationCycle::LEVELS)->map(function (string $levelName) use ($cyclesByLevel) {
            $cycle = $cyclesByLevel->firstWhere('level', $levelName);

            if (! $cycle) {
                return [
                    'level' => $levelName,
                    'cycleId' => null,
                    'cycleStatus' => null,
                    'displayStatus' => 'Not Started',
                    'assignedCount' => 0,
                    'totalAreas' => count(AccreditationArea::AACCUP_AREAS),
                    'areas' => [],
                ];
            }

            $this->seedFixedAreas($cycle);
            $areas = $cycle->areas()
                ->with(['chair', 'members.user'])
                ->whereNotNull('code')
                ->orderBy('id')
                ->get();

            return [
                'level' => $levelName,
                'cycleId' => $cycle->id,
                'cycleStatus' => $cycle->status,
                'displayStatus' => $cycle->display_status,
                'assignedCount' => $areas->whereNotNull('chair_id')->count(),
                'totalAreas' => count(AccreditationArea::AACCUP_AREAS),
                'areas' => AccreditationAreaResource::collection($areas)->resolve(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Accreditation areas retrieved successfully.',
            'data' => [
                'programId' => $program->id,
                'programName' => $program->name,
                'activeCycleId' => $program->active_cycle_id,
                'activeLevel' => $program->activeCycle?->level,
                'lockedToActiveLevel' => $user->isLockedToProgramActiveLevel(),
                'levels' => $levels->values(),
            ],
        ], 200);
    }

    /**
     * Level-first Area Documents tree for the authenticated Program Chair.
     *
     * Returns Level I–IV folders, the 10 AACCUP areas under each existing
     * cycle, the assigned Area In-Charge, area/review status, and document
     * counts. Documents themselves are loaded per area via GET /documents.
     */
    public function programChairAreaDocuments(Request $request)
    {
        $user = $request->user() ?? $request->user('api');
        $program = $this->resolveVisibleProgram($request, $user, 'You need a program assigned before browsing area documents.');
        $cyclesByLevel = $program->accreditationCycles
            ->sortByDesc('created_at')
            ->unique('level')
            ->values();

        $levels = collect(AccreditationCycle::LEVELS)->map(function (string $levelName) use ($cyclesByLevel, $user) {
            $cycle = $cyclesByLevel->firstWhere('level', $levelName);

            if (! $cycle) {
                return [
                    'level' => $levelName,
                    'cycleId' => null,
                    'cycleStatus' => null,
                    'displayStatus' => 'Not Started',
                    'documentCount' => 0,
                    'areas' => [],
                ];
            }

            $this->seedFixedAreas($cycle);
            $cycle->loadMissing('program');

            $areas = $cycle->areas()
                ->with(['chair', 'reviews' => fn ($q) => $q->latest()])
                ->withCount('documents')
                ->whereNotNull('code')
                ->orderBy('id')
                ->get();

            return [
                'level' => $levelName,
                'cycleId' => $cycle->id,
                'cycleStatus' => $cycle->status,
                'displayStatus' => $cycle->display_status,
                'documentCount' => $areas->sum('documents_count'),
                'areas' => $areas->map(function (AccreditationArea $area) use ($user, $cycle) {
                    $review = $area->reviews->first();
                    if ($review) {
                        $review->setRelation('area', $area);
                        $review->setRelation('cycle', $cycle);
                    }

                    return [
                        'id' => $area->id,
                        'code' => $area->code,
                        'name' => $area->name,
                        'status' => $area->status,
                        'documentCount' => (int) $area->documents_count,
                        'chair' => $area->chair ? [
                            'id' => $area->chair->id,
                            'name' => $area->chair->name,
                            'email' => $area->chair->email,
                        ] : null,
                        'review' => $this->serializeAreaReview($review, $user),
                    ];
                })->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Area documents retrieved successfully.',
            'data' => [
                'programId' => $program->id,
                'programName' => $program->name,
                'activeCycleId' => $program->active_cycle_id,
                'activeLevel' => $program->activeCycle?->level,
                'lockedToActiveLevel' => $user->isLockedToProgramActiveLevel(),
                'levels' => $levels->values(),
            ],
        ], 200);
    }

    private function resolveVisibleProgram(Request $request, ?User $user, string $missingProgramMessage): Program
    {
        if (! $user) {
            abort(403, 'You are not allowed to browse this program\'s areas.');
        }

        $canBrowse = $user->isProgramChair()
            || $user->isFaculty()
            || $user->isAreaIncharge()
            || $user->isDean()
            || $user->isQA()
            || $user->isVPAA()
            || $user->isSuperAdmin();

        if (! $canBrowse) {
            abort(403, 'You are not allowed to browse this program\'s areas.');
        }

        $requestedProgramId = $request->filled('program_id') ? (int) $request->input('program_id') : null;

        if ($requestedProgramId && ($user->isQA() || $user->isVPAA() || $user->isSuperAdmin())) {
            return Program::with(['accreditationCycles', 'activeCycle'])->findOrFail($requestedProgramId);
        }

        if ($requestedProgramId && $user->isDean()) {
            $program = Program::with(['accreditationCycles', 'activeCycle'])->findOrFail($requestedProgramId);
            abort_unless((int) $program->college_id === (int) $user->getEffectiveCollegeId(), 403, 'You are not allowed to browse this program\'s areas.');

            return $program;
        }

        if ($requestedProgramId && $user->isProgramChair()) {
            abort_unless($user->ownsAssignedProgram($requestedProgramId), 403, 'You are not allowed to browse this program\'s areas.');

            return Program::with(['accreditationCycles', 'activeCycle'])->findOrFail($requestedProgramId);
        }

        $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();
        if (! $programId) {
            abort(422, $missingProgramMessage);
        }

        return Program::with(['accreditationCycles', 'activeCycle'])->findOrFail($programId);
    }

    private function serializeAreaReview(?Review $review, $user): ?array
    {
        if (! $review) {
            return null;
        }

        return [
            'id' => $review->id,
            'currentStatus' => $review->current_status,
            'expectedReviewerRole' => $review->getExpectedReviewerRole(),
            'isTerminal' => $review->isTerminal(),
            'submittedAt' => $review->submitted_at?->toDateTimeString(),
            'canApprove' => $user->can('approve', $review),
            'canRequestRevision' => $user->can('requestRevision', $review),
        ];
    }

    /**
     * Set / update the submission deadline for an accreditation area.
     */
    public function setDeadline(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanManageCycle($request->user(), $accreditationArea->cycle()->firstOrFail());
        $validated = $request->validate([
            'deadline' => ['required', 'date'],
        ]);

        $accreditationArea->update(['deadline' => $validated['deadline']]);

        return response()->json([
            'success' => true,
            'message' => 'Submission deadline updated successfully.',
            'data' => new AccreditationAreaResource($accreditationArea->load('chair', 'members.user')),
        ], 200);
    }

    /**
     * Set / replace the member list for an accreditation area.
     *
     * The current Area Chair is always excluded so a user cannot be both
     * Area Chair and Member of the same area at the same time.
     */
    public function setMembers(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanManageCycle($request->user(), $accreditationArea->cycle()->firstOrFail());
        $validated = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $chairId = $accreditationArea->chair_id;

        $userIds = collect($validated['user_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(function ($id) use ($chairId) {
                return $chairId === null || $id !== (int) $chairId;
            })
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($accreditationArea, $userIds) {
            $accreditationArea->members()->delete();
            foreach ($userIds as $userId) {
                $accreditationArea->members()->create([
                    'user_id' => $userId,
                    'role' => 'member',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Area members updated successfully.',
            'data' => new AccreditationAreaResource($accreditationArea->fresh('chair', 'members.user')),
        ], 200);
    }

    private function seedFixedAreas(AccreditationCycle $cycle): void
    {
        foreach (AccreditationArea::AACCUP_AREAS as $areaDef) {
            AccreditationArea::firstOrCreate(
                [
                    'cycle_id' => $cycle->id,
                    'code' => $areaDef['code'],
                ],
                [
                    'name' => $areaDef['name'],
                    'status' => 'Not Started',
                ]
            );
        }
    }

    private function assertCanViewCycle($user, AccreditationCycle $cycle): void
    {
        if (! $user || $user->isVPAA() || $user->isDean()) {
            return;
        }

        if ($user->isProgramChair() && (int) $cycle->program()->value('chair_id') === (int) $user->id) {
            return;
        }

        if ($user->isAreaIncharge() && $cycle->areas()->where('chair_id', $user->id)->exists()) {
            return;
        }

        abort(403, 'You are not authorized to view this accreditation area.');
    }

    private function assertCanManageCycle($user, AccreditationCycle $cycle): void
    {
        if (! $user || ! $user->isProgramChair() || (int) $cycle->program()->value('chair_id') !== (int) $user->id) {
            abort(403, 'Only the assigned Program Chair may manage this accreditation area.');
        }
    }
}