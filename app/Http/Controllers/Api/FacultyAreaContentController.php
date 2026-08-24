<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationParameterResource;
use App\Http\Resources\MyAreaResource;
use App\Http\Resources\ParameterContentRowResource;
use App\Models\AccreditationArea;
use App\Models\AccreditationParameter;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowStatus;
use App\Models\User;
use App\Services\AreaProgressService;
use App\Support\AreaParameterCatalog;
use Illuminate\Http\Request;

class FacultyAreaContentController extends Controller
{
    public function myAreas(Request $request)
    {
        $user = $request->user();
        $areaIds = $user->assignedAreaIds();

        $areas = AccreditationArea::with(['chair', 'cycle.program'])
            ->whereIn('id', $areaIds)
            ->orderByRaw("CASE WHEN code IS NULL THEN 1 ELSE 0 END")
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        if ($user->isLockedToProgramActiveLevel()) {
            $areas = $areas->filter(function (AccreditationArea $area) {
                $activeCycleId = $area->cycle?->program?->active_cycle_id;
                if (! $activeCycleId) {
                    return true;
                }

                return (int) $area->cycle_id === (int) $activeCycleId;
            })->values();
        }

        return response()->json([
            'success' => true,
            'message' => 'Assigned areas retrieved successfully.',
            'data' => MyAreaResource::collection($areas),
            'meta' => [
                'lockedToActiveLevel' => $user->isLockedToProgramActiveLevel(),
            ],
        ]);
    }

    public function qaAreas(Request $request)
    {
        $this->assertCanEditContent($request->user());

        $areas = AccreditationArea::with(['chair', 'cycle.program'])
            ->whereNotNull('code')
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Accreditation areas retrieved successfully.',
            'data' => MyAreaResource::collection($areas),
        ]);
    }

    public function parameters(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanViewArea($request->user(), $accreditationArea);

        AreaParameterCatalog::ensureSeeded($accreditationArea);

        $parameters = $accreditationArea->parameters()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Area parameters retrieved successfully.',
            'data' => AccreditationParameterResource::collection($parameters),
        ]);
    }

    public function storeParameter(Request $request, AccreditationArea $accreditationArea)
    {
        $this->assertCanEditContent($request->user());

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $parameter = AccreditationParameter::create([
            'area_id' => $accreditationArea->id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? ((int) $accreditationArea->parameters()->max('sort_order') + 1),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Parameter created successfully.',
            'data' => new AccreditationParameterResource($parameter),
        ], 201);
    }

    public function rows(Request $request, AccreditationParameter $parameter)
    {
        $parameter->load('area');
        $this->assertCanViewArea($request->user(), $parameter->area);

        AreaParameterCatalog::ensureSeeded($parameter->area);

        $rows = $parameter->contentRows()
            ->with(['status.doneBy', 'latestDocument.versions', 'latestDocument.uploader'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Parameter content rows retrieved successfully.',
            'data' => ParameterContentRowResource::collection($rows),
        ]);
    }

    public function storeRow(Request $request, AccreditationParameter $parameter)
    {
        $this->assertCanEditContent($request->user());

        $validated = $request->validate([
            'content' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => $validated['content'],
            'sort_order' => $validated['sort_order'] ?? ((int) $parameter->contentRows()->max('sort_order') + 1),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $parameter->loadMissing('area');
        app(AreaProgressService::class)->refresh($parameter->area);

        return response()->json([
            'success' => true,
            'message' => 'Parameter content row created successfully.',
            'data' => new ParameterContentRowResource($row->load(['status.doneBy', 'latestDocument.versions', 'latestDocument.uploader'])),
        ], 201);
    }

    public function updateStatus(Request $request, ParameterContentRow $parameterContentRow)
    {
        $parameterContentRow->load('parameter.area');
        $this->assertCanToggleStatus($request->user(), $parameterContentRow->parameter->area);

        $validated = $request->validate([
            'is_done' => ['required', 'boolean'],
        ]);

        $isDone = (bool) $validated['is_done'];

        $status = ParameterRowStatus::updateOrCreate(
            ['content_row_id' => $parameterContentRow->id],
            [
                'is_done' => $isDone,
                'done_by' => $isDone ? $request->user()->id : null,
                'done_at' => $isDone ? now() : null,
            ]
        );

        $parameterContentRow->setRelation('status', $status->load('doneBy'));
        $parameterContentRow->loadMissing(['latestDocument.versions', 'latestDocument.uploader']);

        app(AreaProgressService::class)->refresh($parameterContentRow->parameter?->area);

        return response()->json([
            'success' => true,
            'message' => $isDone ? 'Row marked as done.' : 'Row marked as not done.',
            'data' => new ParameterContentRowResource($parameterContentRow),
        ]);
    }

    public function updateContent(Request $request, ParameterContentRow $parameterContentRow)
    {
        $this->assertCanEditContent($request->user());

        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $parameterContentRow->update([
            'content' => $validated['content'],
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Row content updated successfully.',
            'data' => new ParameterContentRowResource(
                $parameterContentRow->load(['status.doneBy', 'latestDocument.versions', 'latestDocument.uploader'])
            ),
        ]);
    }

    public function destroyRow(Request $request, ParameterContentRow $parameterContentRow)
    {
        $this->assertCanEditContent($request->user());

        $parameterContentRow->load('parameter.area');
        $area = $parameterContentRow->parameter?->area;
        $parameterContentRow->delete();

        if ($area) {
            app(AreaProgressService::class)->refresh($area);
        }

        return response()->json([
            'success' => true,
            'message' => 'Parameter content row deleted successfully.',
        ]);
    }

    private function assertCanViewArea(?User $user, ?AccreditationArea $area): void
    {
        if (! $user || ! $area) {
            abort(403, 'You are not allowed to view this area.');
        }

        if ($user->isQA() || $user->isVPAA() || $user->isSuperAdmin()) {
            return;
        }

        if ($user->isAssignedToArea($area)) {
            return;
        }

        abort(403, 'You are not assigned to this area.');
    }

    private function assertCanToggleStatus(?User $user, ?AccreditationArea $area): void
    {
        if (! $user || ! $area || ! $user->isAssignedToArea($area)) {
            abort(403, 'Only assigned area chairs or members may mark rows as done.');
        }
    }

    private function assertCanEditContent(?User $user): void
    {
        if (! $user || ! ($user->isQA() || $user->isVPAA() || $user->isSuperAdmin())) {
            abort(403, 'Only QA or VPAA/DI may edit parameter content.');
        }
    }
}
