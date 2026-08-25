<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramActiveLevelController extends Controller
{
    public function show(Request $request, Program $program)
    {
        $this->authorize('view', $program);

        return response()->json([
            'success' => true,
            'message' => 'Program active level retrieved successfully.',
            'data' => $this->serialize($program),
        ]);
    }

    public function update(Request $request, Program $program)
    {
        $this->assertCanSetActiveLevel($request->user(), $program);

        $validated = $request->validate([
            'cycle_id' => ['nullable', 'integer', 'exists:accreditation_cycles,id'],
            'level' => ['nullable', 'string', Rule::in(AccreditationCycle::LEVELS)],
        ]);

        $cycle = null;

        if (! empty($validated['cycle_id'])) {
            $cycle = $program->accreditationCycles()->whereKey($validated['cycle_id'])->first();

            if (! $cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected cycle does not belong to this program.',
                ], 422);
            }
        } elseif (! empty($validated['level'])) {
            $cycle = app(\App\Services\AaccupStructureService::class)
                ->ensureCycle($program, $validated['level']);
        }

        $program->active_cycle_id = $cycle?->id;

        if ($cycle) {
            $program->accreditation_level = $cycle->level;
        }

        $program->save();

        return response()->json([
            'success' => true,
            'message' => $cycle
                ? 'Active accreditation level updated.'
                : 'Active accreditation level cleared.',
            'data' => $this->serialize($program->fresh()),
        ]);
    }

    private function assertCanSetActiveLevel(?User $user, Program $program): void
    {
        if (! $user) {
            abort(403, 'Only the Program Chair can set the active accreditation level.');
        }

        if ($user->isSuperAdmin()) {
            return;
        }

        if ($user->isProgramChair() && (int) $program->chair_id === (int) $user->id) {
            return;
        }

        abort(403, 'Only the Program Chair can set the active accreditation level.');
    }

    private function serialize(Program $program): array
    {
        $program->loadMissing(['activeCycle', 'accreditationCycles']);

        $cyclesByLevel = $program->accreditationCycles
            ->sortByDesc('created_at')
            ->unique('level')
            ->values();

        $levels = collect(AccreditationCycle::LEVELS)->map(function (string $levelName) use ($cyclesByLevel) {
            $cycle = $cyclesByLevel->firstWhere('level', $levelName);

            return [
                'level' => $levelName,
                'cycleId' => $cycle?->id,
                'cycleStatus' => $cycle?->status,
                'displayStatus' => $cycle?->display_status ?? 'Not Started',
                'selectable' => $cycle !== null,
            ];
        });

        return [
            'programId' => $program->id,
            'activeCycleId' => $program->active_cycle_id,
            'activeLevel' => $program->activeCycle?->level,
            'levels' => $levels->values(),
        ];
    }
}
