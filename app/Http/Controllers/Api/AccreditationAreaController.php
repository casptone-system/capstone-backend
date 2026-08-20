<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationAreaResource;
use App\Http\Resources\AreaMemberResource;
use App\Models\AccreditationArea;
use App\Models\AreaMember;
use App\Models\AccreditationCycle;
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
        ]);

        // A user cannot be both Area Chair and Member of the same area.
        $accreditationArea->members()->where('user_id', $validated['chair_id'])->delete();

        $accreditationArea->update(['chair_id' => $validated['chair_id']]);

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
     * List the 10 fixed AACCUP areas for the authenticated Program Chair.
     *
     * Seeding is idempotent: the fixed areas for the program's latest
     * accreditation cycle are created once if missing, and subsequent
     * requests simply return the persisted rows with their current chair,
     * members and deadline.
     */
    public function programChairAreas(Request $request)
    {
        $user = $request->user() ?? $request->user('api');
        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only a Program Chair can manage area assignments.');
        }

        $programId = $user->getEffectiveProgramId();
        if (! $programId) {
            abort(422, 'You need a program assigned before managing area assignments.');
        }

        $cycle = AccreditationCycle::where('program_id', $programId)
            ->orderByDesc('created_at')
            ->first();

        if (! $cycle) {
            return response()->json(['success' => true, 'data' => []], 200);
        }

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

        $areas = $cycle->areas()
            ->with(['chair', 'members.user'])
            ->whereNotNull('code')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Accreditation areas retrieved successfully.',
            'data' => AccreditationAreaResource::collection($areas),
        ], 200);
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
            'user_ids' => ['required', 'array'],
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