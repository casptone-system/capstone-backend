<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccreditationArea;
use App\Models\AccreditationParameter;
use App\Models\AccreditationRequirement;
use App\Models\AccreditationWorkspace;
use App\Models\AreaMember;
use App\Models\User;
use App\Notifications\AreaInChargeAssignedNotification;
use App\Services\AccreditationWorkspaceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccreditationWorkspaceController extends Controller
{
    public function __construct(private AccreditationWorkspaceService $workspaces)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = AccreditationWorkspace::with(['program', 'cycle'])->latest();

        if ($user->isProgramChair()) {
            $query->where('program_id', $user->assignedProgramId() ?: 0);
        } elseif ($user->isDean()) {
            $collegeId = $user->getEffectiveCollegeId();
            $query->whereHas('program', fn ($program) => $program->where('college_id', $collegeId ?: 0));
        } elseif ($user->isFaculty() || $user->isAreaIncharge()) {
            $query->where('program_id', $user->getEffectiveProgramId() ?: 0);
        } elseif (! $user->isVPAA() && ! $user->isQA() && ! $user->isSuperAdmin()) {
            abort(403);
        }

        $items = $query->get()
            ->map(fn (AccreditationWorkspace $workspace) => $this->workspaces->serializeWorkspace($workspace, $user))
            ->filter(function (array $item) use ($user) {
                if ($user->isFaculty() || $user->isAreaIncharge()) {
                    return count($item['areas'] ?? []) > 0;
                }

                return true;
            })
            ->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'level' => ['required', 'in:Level I,Level II,Level III,Level IV'],
            'deadline' => ['nullable', 'date'],
        ]);

        $workspace = $this->workspaces->createForProgramChair(
            $request->user(),
            $validated['level'],
            $validated['deadline'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Accreditation folder created.',
            'data' => $this->workspaces->serializeWorkspace($workspace, $request->user()),
        ], 201);
    }

    public function show(Request $request, AccreditationWorkspace $workspace)
    {
        $this->assertCanView($request->user(), $workspace);

        return response()->json([
            'success' => true,
            'data' => $this->workspaces->serializeWorkspace($workspace, $request->user()),
        ]);
    }

    public function assignChair(Request $request, AccreditationWorkspace $workspace, AccreditationArea $area)
    {
        $this->assertProgramChairOwns($request->user(), $workspace);
        $this->assertAreaInWorkspace($area, $workspace);

        $validated = $request->validate([
            'chair_id' => ['required', 'exists:users,id'],
        ]);

        $candidate = User::findOrFail($validated['chair_id']);
        if (! $candidate->belongsToProgram((int) $workspace->program_id) || (! $candidate->isFaculty() && ! $candidate->isAreaIncharge())) {
            abort(422, 'Select a faculty member from this program.');
        }

        $area->update(['chair_id' => $candidate->id]);
        if (! $candidate->isAreaIncharge()) {
            $candidate->assignRole('Area In-Charge');
        }
        $candidate->notify(new AreaInChargeAssignedNotification($area->fresh(['cycle.program'])));

        return response()->json([
            'success' => true,
            'message' => 'Area Chair assigned successfully.',
            'data' => $this->workspaces->serializeWorkspace($workspace->fresh(), $request->user()),
        ]);
    }

    public function addMember(Request $request, AccreditationWorkspace $workspace, AccreditationArea $area)
    {
        $this->assertProgramChairOwns($request->user(), $workspace);
        $this->assertAreaInWorkspace($area, $workspace);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $candidate = User::findOrFail($validated['user_id']);
        if (! $candidate->belongsToProgram((int) $workspace->program_id)) {
            abort(422, 'The selected user does not belong to this program.');
        }

        if ($area->members()->where('user_id', $candidate->id)->exists()) {
            abort(422, 'This faculty is already a member of this area.');
        }

        $area->members()->create([
            'user_id' => $candidate->id,
            'role' => 'member',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Area member added.',
            'data' => $this->workspaces->serializeWorkspace($workspace->fresh(), $request->user()),
        ], 201);
    }

    public function removeMember(Request $request, AccreditationWorkspace $workspace, AccreditationArea $area, User $user)
    {
        $this->assertProgramChairOwns($request->user(), $workspace);
        $this->assertAreaInWorkspace($area, $workspace);

        AreaMember::where('area_id', $area->id)->where('user_id', $user->id)->delete();

        if ((int) $area->chair_id === (int) $user->id) {
            $area->update(['chair_id' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Area member removed.',
            'data' => $this->workspaces->serializeWorkspace($workspace->fresh(), $request->user()),
        ]);
    }

    public function parameter(Request $request, AccreditationWorkspace $workspace, AccreditationParameter $parameter)
    {
        $this->assertCanView($request->user(), $workspace);
        $area = $parameter->area;
        $this->assertAreaInWorkspace($area, $workspace);

        $serialized = $this->workspaces->serializeArea($area->load(['chair', 'members.user', 'parameters.criteria']), $workspace);
        $parameterPayload = collect($serialized['parameters'])->firstWhere('id', $parameter->id);

        return response()->json([
            'success' => true,
            'data' => [
                'workspace' => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'level' => $workspace->level,
                    'deadline' => $workspace->deadline?->toDateString(),
                ],
                'area' => [
                    'id' => $area->id,
                    'name' => $area->name,
                ],
                'parameter' => $parameterPayload,
            ],
        ]);
    }

    public function uploadEvidence(Request $request, AccreditationWorkspace $workspace, AccreditationRequirement $requirement)
    {
        $this->assertCanView($request->user(), $workspace);
        $this->assertAreaInWorkspace($requirement->area, $workspace);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $this->workspaces->storeEvidence($request->user(), $requirement, $request->file('file'), $workspace);

        return response()->json([
            'success' => true,
            'message' => 'File attached.',
            'data' => $this->workspaces->serializeWorkspace($workspace->fresh(), $request->user()),
        ]);
    }

    public function markDone(Request $request, AccreditationWorkspace $workspace, AccreditationRequirement $requirement)
    {
        $this->assertCanView($request->user(), $workspace);
        $this->assertAreaInWorkspace($requirement->area, $workspace);
        $this->workspaces->markDone($request->user(), $requirement, $workspace);

        return response()->json([
            'success' => true,
            'message' => 'Criterion marked as done.',
            'data' => $this->workspaces->serializeWorkspace($workspace->fresh(), $request->user()),
        ]);
    }

    public function progress(Request $request, AccreditationWorkspace $workspace)
    {
        $this->assertCanView($request->user(), $workspace);
        $payload = $this->workspaces->serializeWorkspace($workspace, $request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'overallProgress' => $payload['overallProgress'],
                'remaining' => max(0, 100 - $payload['overallProgress']),
                'areas' => collect($payload['areas'])->map(fn ($area) => [
                    'id' => $area['id'],
                    'name' => $area['name'],
                    'progress' => $area['progress'],
                    'remaining' => max(0, 100 - $area['progress']),
                    'chair' => $area['chair']['name'] ?? null,
                ])->values(),
            ],
        ]);
    }

    public function downloadEvidence(Request $request, AccreditationWorkspace $workspace, int $evidence): StreamedResponse
    {
        $this->assertCanView($request->user(), $workspace);
        $record = \App\Models\CriterionEvidence::where('workspace_id', $workspace->id)->findOrFail($evidence);

        return response()->streamDownload(function () use ($record) {
            echo \Illuminate\Support\Facades\Storage::disk('private')->get($record->file_path);
        }, $record->original_name);
    }

    private function assertProgramChairOwns(User $user, AccreditationWorkspace $workspace): void
    {
        if (! $user->isProgramChair() || ! $user->ownsAssignedProgram((int) $workspace->program_id)) {
            abort(403, 'Only the assigned Program Chair may manage this accreditation folder.');
        }
    }

    private function assertCanView(User $user, AccreditationWorkspace $workspace): void
    {
        if ($user->isVPAA() || $user->isQA() || $user->isSuperAdmin()) {
            return;
        }
        if ($user->isDean() && (int) $workspace->program?->college_id === (int) $user->getEffectiveCollegeId()) {
            return;
        }
        if ($user->isProgramChair() && $user->ownsAssignedProgram((int) $workspace->program_id)) {
            return;
        }
        if (($user->isFaculty() || $user->isAreaIncharge()) && (int) $user->getEffectiveProgramId() === (int) $workspace->program_id) {
            return;
        }

        abort(403, 'You are not authorized to view this accreditation folder.');
    }

    private function assertAreaInWorkspace(AccreditationArea $area, AccreditationWorkspace $workspace): void
    {
        if ((int) $area->cycle_id !== (int) $workspace->cycle_id) {
            abort(403, 'This area does not belong to the selected accreditation folder.');
        }
    }
}
