<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationCycleResource;
use App\Models\AccreditationCycle;
use App\Models\Program;
use Illuminate\Http\Request;

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
        $validated = $request->validate([
            'program_id' => ['required', 'exists:programs,id'],
            'level' => ['required', 'in:' . implode(',', AccreditationCycle::LEVELS)],
            'status' => ['required', 'in:' . implode(',', AccreditationCycle::STATUSES)],
            'valid_until' => ['nullable', 'date'],
            'scheduled_visit' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $cycle = AccreditationCycle::create($validated);

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
            'program_id' => ['sometimes', 'required', 'exists:programs,id'],
            'level' => ['sometimes', 'required', 'in:' . implode(',', AccreditationCycle::LEVELS)],
            'status' => ['sometimes', 'required', 'in:' . implode(',', AccreditationCycle::STATUSES)],
            'valid_until' => ['nullable', 'date'],
            'scheduled_visit' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

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
}