<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationCycleResource;
use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AccreditationCycleNoticeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AccreditationCycleController extends Controller
{
    /**
     * Display a paginated list of accreditation cycles.
     */
    public function index(Request $request)
    {
        $query = AccreditationCycle::with('program');

        if ($request->filled('program_id')) {
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

        $dean = User::where('college_id', $program->college_id)
            ->whereHas('roles', function ($query) {
                $query->where('name', 'Dean')->orWhere('name', 'dean');
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
        $accreditationCycle->load('program');

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
        if (! $actor?->isProgramChair()) {
            unset($validated['level'], $validated['phase']);
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
    public function destroy(AccreditationCycle $accreditationCycle)
    {
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
     * Allow Program Chair to update accreditation Level, Phase, and Dates (program setup)
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
            'scheduled_visit' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

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
            if (! $user->college_id && $user->getEffectiveCollegeId() !== $collegeId) {
                abort(403, 'This dean is not assigned to this college.');
            }
        }
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

        $cycles = AccreditationCycle::with(['program.college'])
            ->orderByDesc('scheduled_visit')
            ->orderByDesc('created_at')
            ->get();

        $upcoming = $cycles->filter(fn ($cycle) => $cycle->scheduled_visit)
            ->sortBy(fn ($cycle) => $cycle->scheduled_visit)
            ->values();

        $ready = $cycles->filter(fn ($cycle) => in_array($cycle->status, ['Ready', 'Completed'], true))->values();

        $atRisk = $cycles->filter(function ($cycle) {
            if (in_array($cycle->status, ['Ready', 'Completed', 'Expired'], true)) {
                return false;
            }

            return $cycle->phase === 'VPAA Monitoring'
                || $cycle->status === 'Internal Review'
                || $cycle->scheduled_visit && $cycle->scheduled_visit->isPast();
        })->values();

        $accreditations = $cycles->map(function ($cycle) {
            $program = $cycle->program;
            $college = $program?->college;
            $evidenceScore = 0;
            $documentCount = $program ? \App\Models\Document::where('program_id', $program->id)->count() : 0;
            $totalAreas = $program ? \App\Models\AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))->count() : 0;
            if ($totalAreas > 0) {
                $evidenceScore = (int) round(($documentCount / max(1, $totalAreas)) * 100);
            }

            return [
                'id' => $cycle->id,
                'program' => $program?->name ?? 'Unknown Program',
                'college' => $college?->name ?? 'Unknown College',
                'level' => $cycle->level,
                'phase' => $cycle->phase ?? 'Initial Notice',
                'status' => $cycle->status,
                'accreditation_date' => $cycle->scheduled_visit?->toDateString(),
                'deadline' => $cycle->valid_until?->toDateString(),
                'readiness' => (int) min(100, max(0, $evidenceScore ?: ($cycle->status === 'Ready' ? 100 : 0))),
                'readiness_label' => $cycle->readiness,
                'evidence_completion' => $evidenceScore,
                'program_id' => $program?->id,
                'college_id' => $college?->id,
                'created_at' => $cycle->created_at?->toDateTimeString(),
                'updated_at' => $cycle->updated_at?->toDateTimeString(),
            ];
        })->values();

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
                    'program' => $cycle->program?->name,
                    'college' => $cycle->program?->college?->name,
                    'level' => $cycle->level,
                    'phase' => $cycle->phase ?? 'Initial Notice',
                    'accreditation_date' => $cycle->scheduled_visit?->toDateString(),
                    'status' => $cycle->status,
                ])->values(),
                'at_risk' => $atRisk->map(fn ($cycle) => [
                    'program' => $cycle->program?->name,
                    'college' => $cycle->program?->college?->name,
                    'level' => $cycle->level,
                    'phase' => $cycle->phase ?? 'Initial Notice',
                    'status' => $cycle->status,
                    'risk' => 'Requires VPAA attention',
                ])->values(),
                'readiness' => [
                    'overall' => $summary['overall_readiness'],
                    'programs' => $accreditations->map(fn ($item) => [
                        'program' => $item['program'],
                        'college' => $item['college'],
                        'readiness' => $item['readiness'],
                        'phase' => $item['phase'],
                    ])->values(),
                ],
                'notifications' => $notifications,
                'recent_activity' => $recentActivity,
            ],
        ], 200);
    }
}