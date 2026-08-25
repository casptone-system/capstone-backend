<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationCycleResource;
use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AccreditationCycleNoticeNotification;
use App\Services\AccreditationLevelStatusService;
use App\Support\ActiveCycle;
use App\Support\OrgScope;
use App\Support\RoleGate;
use App\Support\RoleSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AccreditationCycleController extends Controller
{
    /**
     * Display a paginated list of accreditation cycles.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = AccreditationCycle::with('program.college');
        OrgScope::constrainCycles($query, $user);

        if ($request->filled('program_id')) {
            abort_unless(OrgScope::canSeeProgram($user, (int) $request->program_id), 403, 'You are not allowed to view this program.');
            $query->where('program_id', $request->program_id);
        }

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('level', 'like', "%{$request->search}%")
                    ->orWhere('status', 'like', "%{$request->search}%")
                    ->orWhere('remarks', 'like', "%{$request->search}%");
            });
        }

        if ($request->boolean('active_only') && ! $request->filled('level')) {
            $cycles = ActiveCycle::uniquePerProgram(
                $query->orderByDesc('created_at')->get()
            );

            return response()->json([
                'success' => true,
                'message' => 'Accreditation cycles retrieved successfully.',
                'data' => AccreditationCycleResource::collection($cycles),
            ], 200);
        }

        $cycles = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Accreditation cycles retrieved successfully.',
            'data' => AccreditationCycleResource::collection($cycles),
        ], 200);
    }

    /**
     * Store a newly created accreditation cycle.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isVPAA()) {
            abort(403, 'Only the VPAA/DI can create an accreditation cycle.');
        }

        $validated = $request->validate([
            'college_id' => ['required', 'exists:colleges,id'],
            'program_id' => ['required', 'exists:programs,id'],
            'level' => ['nullable', 'in:' . implode(',', AccreditationCycle::LEVELS)],
            'status' => ['required', 'in:' . implode(',', AccreditationCycle::STATUSES)],
            'phase' => ['nullable', 'string', 'max:255'],
            'instrument_name' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'scheduled_visit' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);
        
        // Set initial workflow status
        $validated['workflow_status'] = 'Initial Notice';
        $validated['level'] = $validated['level'] ?? 'Level I';
        $validated['phase'] = $validated['phase'] ?? null;

        $program = Program::findOrFail($validated['program_id']);

        if ((int) $program->college_id !== (int) $validated['college_id']) {
            $validator = Validator::make($validated, []);
            $validator->errors()->add('program_id', 'The selected program does not belong to the selected college.');

            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $validated['college_id'] = $program->college_id;

        $cycle = AccreditationCycle::create($validated);
        app(\App\Services\AaccupStructureService::class)->seedCycleAreas($cycle);

        $dean = User::where('college_id', $program->college_id)
            ->whereHas('roles', function ($query) {
                $query->where('name', RoleSlug::DEAN);
            })
            ->first();

        if ($dean) {
            $dean->notify(new AccreditationCycleNoticeNotification($cycle, $user));
        }

        return response()->json([
            'success' => true,
            'message' => 'Accreditation cycle created successfully.',
            'data' => new AccreditationCycleResource($cycle),
        ], 201);
    }

    /**
     * Display the specified accreditation cycle.
     */
    public function show(AccreditationCycle $accreditationCycle)
    {
        $accreditationCycle->load('program.college');

        return response()->json([
            'success' => true,
            'message' => 'Accreditation cycle retrieved successfully.',
            'data' => new AccreditationCycleResource($accreditationCycle),
        ], 200);
    }

    /**
     * Update the specified accreditation cycle.
     */
    public function update(Request $request, AccreditationCycle $accreditationCycle)
    {
        RoleGate::denyQaMutations($request->user());

        $validated = $request->validate([
            'college_id' => ['sometimes', 'required', 'exists:colleges,id'],
            'program_id' => ['sometimes', 'required', 'exists:programs,id'],
            'level' => ['sometimes', 'required', 'in:' . implode(',', AccreditationCycle::LEVELS)],
            'status' => ['sometimes', 'required', 'in:' . implode(',', AccreditationCycle::STATUSES)],
            'phase' => ['nullable', 'string', 'max:255'],
            'instrument_name' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'scheduled_visit' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
            'workflow_status' => ['sometimes', 'in:' . implode(',', AccreditationCycle::WORKFLOW_STATUSES)],
        ]);

        $actor = $request->user();
        $isVpaa = $actor?->isVPAA() || $actor?->isSuperAdmin();
        $isChair = $actor?->isProgramChair();

        if (! $isVpaa && ! $isChair) {
            abort(403, 'You are not allowed to update this accreditation cycle.');
        }

        if (! $isChair && ! $actor?->isSuperAdmin()) {
            unset($validated['level'], $validated['phase']);
        }

        if (! $isVpaa) {
            unset($validated['scheduled_visit'], $validated['valid_until'], $validated['instrument_name']);
        }

        if (array_key_exists('program_id', $validated) || array_key_exists('college_id', $validated)) {
            $programId = $validated['program_id'] ?? $accreditationCycle->program_id;
            $collegeId = $validated['college_id'] ?? $accreditationCycle->college_id ?? Program::find($programId)?->college_id;
            $program = Program::findOrFail($programId);

            if ((int) $program->college_id !== (int) $collegeId) {
                $validator = Validator::make($validated, []);
                $validator->errors()->add('program_id', 'The selected program does not belong to the selected college.');

                throw new \Illuminate\Validation\ValidationException($validator);
            }

            $validated['college_id'] = $program->college_id;
        }

        $accreditationCycle->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Accreditation cycle updated successfully.',
            'data' => new AccreditationCycleResource($accreditationCycle),
        ], 200);
    }

    /**
     * Remove the specified accreditation cycle.
     */
    public function destroy(Request $request, AccreditationCycle $accreditationCycle)
    {
        RoleGate::denyQaMutations($request->user());

        $accreditationCycle->delete();

        return response()->json([
            'success' => true,
            'message' => 'Accreditation cycle deleted successfully.',
        ], 200);
    }

    public function acknowledge(Request $request, AccreditationCycle $accreditationCycle)
    {
        $user = $request->user();
        $this->assertDeanForCycle($user, $accreditationCycle);

        $validated = $request->validate([
            'remarks' => ['nullable', 'string'],
        ]);

        $accreditationCycle->update([
            'workflow_status' => 'Dean Acknowledged',
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
            'remarks' => $validated['remarks'] ?? $accreditationCycle->remarks,
        ]);

        $chair = $accreditationCycle->program?->chairUser;
        if ($chair && $chair->id !== $user->id) {
            $chair->notify(new AccreditationCycleNoticeNotification($accreditationCycle, $user));
        }

        return response()->json([
            'success' => true,
            'message' => 'Dean acknowledged the accreditation notice.',
            'data' => new AccreditationCycleResource($accreditationCycle->fresh()),
        ], 200);
    }

    public function forwardToChair(Request $request, AccreditationCycle $accreditationCycle)
    {
        $user = $request->user();
        $this->assertDeanForCycle($user, $accreditationCycle);

        $validated = $request->validate([
            'remarks' => ['nullable', 'string'],
        ]);

        $chair = $accreditationCycle->program?->chairUser;

        $accreditationCycle->update([
            'workflow_status' => 'Forwarded to Chair',
            'forwarded_by' => $user->id,
            'forwarded_at' => now(),
            'program_chair_id' => $chair?->id ?? $accreditationCycle->program_chair_id,
            'remarks' => $validated['remarks'] ?? $accreditationCycle->remarks,
        ]);

        if ($chair) {
            $chair->notify(new AccreditationCycleNoticeNotification($accreditationCycle, $user));
        }

        return response()->json([
            'success' => true,
            'message' => 'Accreditation cycle was forwarded to the program chair.',
            'data' => new AccreditationCycleResource($accreditationCycle->fresh()),
        ], 200);
    }

    public function setRequirements(Request $request, AccreditationCycle $accreditationCycle)
    {
        $user = $request->user();

        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only the program chair may set accreditation requirements.');
        }

        $program = $accreditationCycle->program;
        if (! $program || (int) ($program->chair_id ?? 0) !== (int) $user->id) {
            abort(403, 'You are not assigned as the program chair for this cycle.');
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string'],
        ]);

        $accreditationCycle->update([
            'workflow_status' => 'Requirements Set',
            'remarks' => $validated['remarks'] ?? $accreditationCycle->remarks,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Program requirements for the accreditation cycle were set.',
            'data' => new AccreditationCycleResource($accreditationCycle->fresh()),
        ], 200);
    }

    /**
     * Allow Program Chair to update accreditation Level and Phase (program setup).
     * Schedule and validity dates are set by the VPAA/DI, not the chair.
     */
    public function programChairSetupInfo(Request $request, AccreditationCycle $accreditationCycle)
    {
        $user = $request->user();

        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only the program chair may update accreditation setup information.');
        }

        $program = $accreditationCycle->program;
        if (! $program || (int) ($program->chair_id ?? 0) !== (int) $user->id) {
            abort(403, 'You are not assigned as the program chair for this program.');
        }

        $validated = $request->validate([
            'level' => ['required', 'in:' . implode(',', AccreditationCycle::LEVELS)],
            'phase' => ['nullable', 'string', 'max:255'],
        ]);

        unset($validated['scheduled_visit'], $validated['valid_until']);

        $before = [
            'level' => $accreditationCycle->level,
            'phase' => $accreditationCycle->phase,
            'workflow_status' => $accreditationCycle->workflow_status,
        ];

        $accreditationCycle->fill($validated);

        if (array_key_exists('phase', $validated) && $validated['phase'] === null) {
            $accreditationCycle->phase = $before['phase'];
        }

        $accreditationCycle->save();

        $workspaceUpdate = ['level' => $accreditationCycle->level];
        if (\Illuminate\Support\Facades\Schema::hasColumn('accreditation_workspaces', 'phase') && $accreditationCycle->phase) {
            $workspaceUpdate['phase'] = $accreditationCycle->phase;
        }
        \App\Models\AccreditationWorkspace::where('cycle_id', $accreditationCycle->id)->update($workspaceUpdate);

        $after = [
            'level' => $accreditationCycle->level,
            'phase' => $accreditationCycle->phase,
            'workflow_status' => $accreditationCycle->workflow_status,
        ];

        \App\Models\AuditLog::create([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'event' => 'SETUP_UPDATED',
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        \App\Models\AuditLogDetail::create([
            'audit_log_id' => \App\Models\AuditLog::latest()->first()?->id,
            'user_agent' => json_encode(['before' => $before, 'after' => $after]),
            'exception' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Accreditation setup information updated successfully.',
            'data' => [
                'accreditation_cycle' => new AccreditationCycleResource($accreditationCycle->fresh()),
                'changes' => [
                    'level' => ['from' => $before['level'], 'to' => $after['level']],
                    'phase' => ['from' => $before['phase'], 'to' => $after['phase']],
                    'workflow_status' => ['from' => $before['workflow_status'], 'to' => $after['workflow_status']],
                ],
            ],
        ], 200);
    }

    /**
     * VPAA/DI sets the accreditation visit date and validity window for a program cycle.
     */
    public function setSchedule(Request $request, AccreditationCycle $accreditationCycle)
    {
        RoleGate::assertCanSetAccreditationSchedule($request->user());

        $validated = $request->validate([
            'scheduled_visit' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        if (! empty($validated['scheduled_visit']) && ! empty($validated['valid_until'])) {
            if ($validated['valid_until'] < $validated['scheduled_visit']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Valid until must be on or after the scheduled visit date.',
                    'errors' => [
                        'valid_until' => ['Valid until must be on or after the scheduled visit date.'],
                    ],
                ], 422);
            }
        }

        $accreditationCycle->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Accreditation schedule and validity were updated.',
            'data' => new AccreditationCycleResource($accreditationCycle->fresh('program.college')),
        ], 200);
    }

    public function deanValidate(Request $request, AccreditationCycle $accreditationCycle)
    {
        $user = $request->user();
        $this->assertDeanForCycle($user, $accreditationCycle);

        $validated = $request->validate([
            'remarks' => ['nullable', 'string'],
        ]);

        $accreditationCycle->update([
            'workflow_status' => 'Dean Validated',
            'status' => 'Internal Review',
            'remarks' => $validated['remarks'] ?? $accreditationCycle->remarks,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'The dean validated the accreditation cycle.',
            'data' => new AccreditationCycleResource($accreditationCycle->fresh()),
        ], 200);
    }

    public function vpaaMonitor(Request $request, AccreditationCycle $accreditationCycle)
    {
        $user = $request->user();
        if (! $user || ! $user->isVPAA()) {
            abort(403, 'Only the VPAA/DI can monitor accreditation readiness.');
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string'],
        ]);

        $accreditationCycle->update([
            'workflow_status' => 'VPAA Monitoring',
            'status' => 'Ready',
            'remarks' => $validated['remarks'] ?? $accreditationCycle->remarks,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'VPAA monitoring status updated for the accreditation cycle.',
            'data' => new AccreditationCycleResource($accreditationCycle->fresh()),
        ], 200);
    }

    private function assertDeanForCycle(?User $user, AccreditationCycle $accreditationCycle): void
    {
        if (! $user || ! $user->isDean()) {
            abort(403, 'Only the dean may act on this accreditation cycle.');
        }

        $collegeId = $accreditationCycle->college_id ?? $accreditationCycle->program?->college_id;
        if (! $collegeId || (int) $user->college_id !== (int) $collegeId) {
            abort(403, 'This dean is not assigned to this college.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVpaaCycle(AccreditationCycle $cycle): array
    {
        $program = $cycle->program;
        $college = $program?->college;
        $documentCount = $program ? \App\Models\Document::where('program_id', $program->id)->count() : 0;
        $totalAreas = \App\Models\AccreditationArea::where('cycle_id', $cycle->id)->whereNotNull('code')->count();
        $evidenceScore = $totalAreas > 0
            ? (int) round(($documentCount / max(1, $totalAreas)) * 100)
            : 0;

        $riskReasons = [];
        if ($cycle->isValidityExpired()) {
            $riskReasons[] = 'Accreditation validity has expired';
        }
        if ($cycle->status === 'Internal Review') {
            $riskReasons[] = 'Still in internal review';
        }
        if ($cycle->phase === 'VPAA Monitoring' || $cycle->workflow_status === 'VPAA Monitoring') {
            $riskReasons[] = 'Awaiting VPAA monitoring';
        }
        if (
            $cycle->scheduled_visit
            && $cycle->scheduled_visit->lt(now()->startOfDay())
            && ! in_array($cycle->status, ['Ready', 'Completed'], true)
        ) {
            $riskReasons[] = 'Visit date has passed while preparation is incomplete';
        }

        $daysUntilVisit = $cycle->scheduled_visit
            ? (int) now()->startOfDay()->diffInDays($cycle->scheduled_visit, false)
            : null;

        return [
            'id' => $cycle->id,
            'program' => $program?->name ?? 'Unknown Program',
            'college' => $college?->name ?? 'Unknown College',
            'level' => $cycle->level,
            'phase' => $cycle->phase ?? 'Initial Notice',
            'status' => $cycle->status,
            'display_status' => $cycle->display_status,
            'workflow_status' => $cycle->workflow_status,
            'preparation_status' => $cycle->preparation_status,
            'validity_status' => $cycle->validity_status,
            'scheduled_visit' => $cycle->scheduled_visit?->toDateString(),
            'valid_until' => $cycle->valid_until?->toDateString(),
            'accreditation_date' => $cycle->scheduled_visit?->toDateString(),
            'deadline' => $cycle->valid_until?->toDateString(),
            'readiness' => (int) min(100, max(0, $evidenceScore ?: ($cycle->status === 'Ready' ? 100 : 0))),
            'readiness_label' => $cycle->readiness,
            'evidence_completion' => $evidenceScore,
            'days_until_visit' => $daysUntilVisit,
            'at_risk' => $riskReasons !== [],
            'risk' => $riskReasons[0] ?? null,
            'risk_reasons' => $riskReasons,
            'program_id' => $program?->id,
            'college_id' => $college?->id,
            'created_at' => $cycle->created_at?->toDateTimeString(),
            'updated_at' => $cycle->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * Get the accreditation history for a specific program.
     */
    public function history(Program $program)
    {
        $cycles = $program->accreditationCycles()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Accreditation history retrieved successfully.',
            'data' => AccreditationCycleResource::collection($cycles),
        ], 200);
    }

    /**
     * Role-scoped Level I–IV accreditation status for dashboard homes.
     * Includes every level per visible program, not only the latest cycle.
     */
    public function levelStatus(Request $request, AccreditationLevelStatusService $service)
    {
        try {
            $programs = $service->forUser($request->user(), $request->query('view'));
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Accreditation level status retrieved successfully.',
            'data' => $programs,
        ], 200);
    }

    /**
     * Get dashboard metrics: current level, expiry date, and readiness
     * for every program, plus an overall summary.
     */
    public function dashboard()
    {
        $programs = Program::with('accreditationCycles')->get();

        $programMetrics = $programs->map(function ($program) {
            $currentCycle = $program->accreditationCycles
                ->sortByDesc('created_at')
                ->first();

            return [
                'programId' => $program->id,
                'programName' => $program->name,
                'programCode' => $program->code,
                'currentLevel' => $currentCycle?->level ?? 'N/A',
                'expiryDate' => $currentCycle?->valid_until?->toDateString() ?? null,
                'readiness' => $currentCycle ? $currentCycle->readiness : 'Not Started',
            ];
        });

        $allCycles = AccreditationCycle::all();

        $cyclesByStatus = array_fill_keys(AccreditationCycle::STATUSES, 0);
        $cyclesByLevel = array_fill_keys(AccreditationCycle::LEVELS, 0);

        foreach ($allCycles as $cycle) {
            if (isset($cyclesByStatus[$cycle->status])) {
                $cyclesByStatus[$cycle->status]++;
            }
            if (isset($cyclesByLevel[$cycle->level])) {
                $cyclesByLevel[$cycle->level]++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Dashboard metrics retrieved successfully.',
            'data' => [
                'programs' => $programMetrics,
                'summary' => [
                    'totalPrograms' => $programs->count(),
                    'totalCycles' => $allCycles->count(),
                    'cyclesByStatus' => $cyclesByStatus,
                    'cyclesByLevel' => $cyclesByLevel,
                ],
            ],
        ], 200);
    }

    public function vpaaDashboard(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isVPAA()) {
            abort(403, 'Only VPAA/DI users can access the institutional dashboard.');
        }

        $cycles = ActiveCycle::uniquePerProgram(
            AccreditationCycle::with(['program.college'])
                ->orderByDesc('scheduled_visit')
                ->orderByDesc('created_at')
                ->get()
        );

        $upcoming = $cycles->filter(fn ($cycle) => $cycle->scheduled_visit)
            ->sortBy(fn ($cycle) => $cycle->scheduled_visit)
            ->values();

        $ready = $cycles->filter(fn ($cycle) => in_array($cycle->status, ['Ready', 'Completed'], true))->values();

        $accreditations = $cycles->map(fn ($cycle) => $this->serializeVpaaCycle($cycle))->values();

        $atRisk = $accreditations->filter(fn ($item) => $item['at_risk'])->values();

        $expiredValidity = $accreditations->filter(fn ($item) => $item['validity_status'] === 'Expired')->values();

        $notifications = $user->notifications()->orderByDesc('created_at')->limit(10)->get()->map(function ($notification) {
            $data = $notification->data ?? [];
            return [
                'id' => $notification->id,
                'title' => $data['title'] ?? 'Institutional update',
                'message' => $data['message'] ?? $data['title'] ?? 'Accreditation update',
                'type' => $data['type'] ?? 'info',
                'is_read' => (bool) $notification->read_at,
                'created_at' => $notification->created_at?->toDateTimeString(),
            ];
        })->values();

        $recentActivity = \App\Models\AuditLog::query()->orderByDesc('created_at')->limit(10)->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'message' => $log->event ?? $log->action ?? 'System activity',
                'actor' => $log->actor_name ?? 'System',
                'created_at' => $log->created_at?->toDateTimeString(),
            ];
        })->values();

        $summary = [
            'active_accreditations' => $cycles->count(),
            'upcoming_accreditations' => $upcoming->count(),
            'ready_programs' => $ready->count(),
            'at_risk_programs' => $atRisk->count(),
            'expired_validity' => $expiredValidity->count(),
            'overall_readiness' => $cycles->count() > 0
                ? (int) round($ready->count() / $cycles->count() * 100)
                : 0,
        ];

        return response()->json([
            'success' => true,
            'message' => 'VPAA dashboard data retrieved successfully.',
            'data' => [
                'summary' => $summary,
                'accreditations' => $accreditations,
                'upcoming' => $upcoming->map(fn ($cycle) => [
                    'id' => $cycle->id,
                    'program' => $cycle->program?->name,
                    'program_id' => $cycle->program_id,
                    'college' => $cycle->program?->college?->name,
                    'level' => $cycle->level,
                    'phase' => $cycle->phase ?? 'Initial Notice',
                    'preparation_status' => $cycle->preparation_status,
                    'validity_status' => $cycle->validity_status,
                    'accreditation_date' => $cycle->scheduled_visit?->toDateString(),
                    'scheduled_visit' => $cycle->scheduled_visit?->toDateString(),
                    'valid_until' => $cycle->valid_until?->toDateString(),
                    'status' => $cycle->status,
                ])->values(),
                'at_risk' => $atRisk->values(),
                'readiness' => [
                    'overall' => $summary['overall_readiness'],
                    'programs' => $accreditations->map(fn ($item) => [
                        'id' => $item['id'],
                        'program' => $item['program'],
                        'college' => $item['college'],
                        'level' => $item['level'],
                        'readiness' => $item['readiness'],
                        'phase' => $item['phase'],
                        'preparation_status' => $item['preparation_status'],
                        'validity_status' => $item['validity_status'],
                        'valid_until' => $item['valid_until'],
                    ])->values(),
                ],
                'notifications' => $notifications,
                'recent_activity' => $recentActivity,
            ],
        ], 200);
    }
}