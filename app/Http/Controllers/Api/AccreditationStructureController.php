<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationAreaResource;
use App\Http\Resources\AccreditationCycleResource;
use App\Http\Resources\AccreditationInstrumentResource;
use App\Http\Resources\AccreditationRequirementResource;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationInstrument;
use App\Models\AccreditationRequirement;
use App\Models\User;
use App\Notifications\AreaInChargeAssignedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccreditationStructureController extends Controller
{
    public function show(Request $request, AccreditationCycle $accreditationCycle)
    {
        $this->assertCanViewCycle($request->user(), $accreditationCycle);

        $accreditationCycle->load([
            'program',
            'instrument.areas.requirements',
            'instrument.areas.chair',
            'instrument.areas.members.user',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'cycle' => new AccreditationCycleResource($accreditationCycle),
                'instrument' => $accreditationCycle->instrument
                    ? new AccreditationInstrumentResource($accreditationCycle->instrument)
                    : null,
                'areas' => AccreditationAreaResource::collection(
                    $accreditationCycle->instrument?->areas ?? collect()
                ),
            ],
        ]);
    }

    public function store(Request $request, AccreditationCycle $accreditationCycle)
    {
        $user = $request->user();
        if (! $user || ! $user->isVPAA()) {
            abort(403, 'Only the VPAA/DI can create an accreditation instrument structure.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'areas' => ['required', 'array', 'min:1'],
            'areas.*.name' => ['required', 'string', 'max:255'],
            'areas.*.description' => ['nullable', 'string'],
            'areas.*.requirements' => ['required', 'array', 'min:1'],
            'areas.*.requirements.*.code' => ['required', 'string', 'max:100'],
            'areas.*.requirements.*.title' => ['required', 'string', 'max:255'],
            'areas.*.requirements.*.description' => ['nullable', 'string'],
            'areas.*.requirements.*.evidence_guidance' => ['nullable', 'string'],
            'areas.*.requirements.*.required_evidence_type' => ['nullable', 'string', 'max:255'],
        ]);

        $instrument = DB::transaction(function () use ($validated, $accreditationCycle) {
            $instrument = AccreditationInstrument::create([
                'name' => $validated['name'],
                'version' => $validated['version'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            foreach ($validated['areas'] as $areaData) {
                $area = $instrument->areas()->create([
                    'cycle_id' => $accreditationCycle->id,
                    'name' => $areaData['name'],
                    'description' => $areaData['description'] ?? null,
                ]);

                foreach ($areaData['requirements'] as $requirementData) {
                    $area->requirements()->create([
                        ...$requirementData,
                        'status' => 'Not Started',
                    ]);
                }
            }

            $accreditationCycle->update([
                'instrument_id' => $instrument->id,
                'instrument_name' => $instrument->name,
            ]);

            return $instrument;
        });

        return response()->json([
            'success' => true,
            'message' => 'Accreditation instrument structure created successfully.',
            'data' => [
                'cycle' => new AccreditationCycleResource($accreditationCycle->fresh('program')),
                'instrument' => new AccreditationInstrumentResource(
                    $instrument->load('areas.requirements', 'areas.chair')
                ),
            ],
        ], 201);
    }

    public function requirements(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanViewCycle($request->user(), $accreditationArea->cycle()->firstOrFail());

        return response()->json([
            'success' => true,
            'data' => AccreditationRequirementResource::collection(
                $accreditationArea->requirements()->orderBy('code')->get()
            ),
        ]);
    }

    public function assignInCharge(Request $request, AccreditationArea $accreditationArea)
    {
        $user = $request->user();
        $cycle = $accreditationArea->cycle()->with('program')->firstOrFail();
        $this->assertProgramChairOwnsCycle($user, $cycle);

        $validated = $request->validate([
            'chair_id' => ['required', 'exists:users,id'],
        ]);
        $assignee = User::findOrFail($validated['chair_id']);

        if (! $assignee->isAreaIncharge() || $assignee->getEffectiveProgramId() !== $cycle->program_id) {
            abort(422, 'The selected user must be an Area In-Charge assigned to this program.');
        }

        $accreditationArea->update(['chair_id' => $assignee->id]);
        $assignee->notify(new AreaInChargeAssignedNotification($accreditationArea->fresh('cycle')));

        return response()->json([
            'success' => true,
            'message' => 'Area In-Charge assigned successfully.',
            'data' => new AccreditationAreaResource(
                $accreditationArea->fresh('chair', 'requirements', 'cycle')
            ),
        ]);
    }

    private function assertCanViewCycle(?User $user, AccreditationCycle $cycle): void
    {
        if (! $user) {
            abort(401);
        }

        if ($user->isVPAA() || $user->isDean()) {
            return;
        }

        if ($user->isProgramChair() && (int) $cycle->program?->chair_id === (int) $user->id) {
            return;
        }

        if ($user->isAreaIncharge() && $cycle->areas()->where('chair_id', $user->id)->exists()) {
            return;
        }

        abort(403, 'You are not authorized to view this accreditation structure.');
    }

    private function assertProgramChairOwnsCycle(?User $user, AccreditationCycle $cycle): void
    {
        if (! $user || ! $user->isProgramChair() || (int) $cycle->program?->chair_id !== (int) $user->id) {
            abort(403, 'Only the assigned Program Chair may manage this accreditation structure.');
        }
    }
}
