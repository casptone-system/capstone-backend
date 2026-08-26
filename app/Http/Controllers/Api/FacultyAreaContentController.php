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
use App\Services\EvidenceStorage;
use App\Support\ActiveCycle;
use App\Support\AreaEvidenceGate;
use App\Support\AreaParameterCatalog;
use Illuminate\Http\Request;

class FacultyAreaContentController extends Controller
{
    public function myAreas(Request $request)
    {
        $user = $request->user();
        $areaIds = $user->assignedAreaIds();

        $areas = AccreditationArea::with(['chair', 'cycle.program.chairUser', 'members.user', 'reviews'])
            ->whereIn('id', $areaIds)
            ->whereNotNull('code')
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->filter(fn (AccreditationArea $area) => $user->isAssignedToArea($area))
            ->values();

        if ($user->isLockedToProgramActiveLevel()) {
            $areas = $areas->filter(function (AccreditationArea $area) {
                $activeCycleId = $area->cycle?->program?->active_cycle_id;
                if (! $activeCycleId) {
                    return true;
                }

                return (int) $area->cycle_id === (int) $activeCycleId;
            })->values();
        }

        $areas = ActiveCycle::uniqueAreasPerProgram($areas);

        $progress = app(AreaProgressService::class);
        $areas->each(fn (AccreditationArea $area) => $progress->refresh($area));

        return response()->json([
            'success' => true,
            'message' => 'Assigned areas retrieved successfully.',
            'data' => MyAreaResource::collection($areas),
            'meta' => [
                'lockedToActiveLevel' => $user->isLockedToProgramActiveLevel(),
                'taskStats' => $progress->workloadForAreas($areas),
                'teamMembers' => $progress->teamMembersForAreas($areas),
            ],
        ]);
    }

    public function qaAreas(Request $request)
    {
        $this->assertCanViewInstitutionAreas($request->user());

        $areas = AccreditationArea::with(['chair', 'cycle.program'])
            ->whereNotNull('code')
            ->orderBy('id')
            ->get();

        if ($request->boolean('catalog')) {
            $areas = $this->uniqueCatalogAreas($areas);
        } else {
            $areas = ActiveCycle::uniqueAreasPerProgram($areas);
        }

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

        $parameters = $accreditationArea->parameters()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->unique('code')
            ->values();

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
            ->with(['status.doneBy', 'documents.versions', 'documents.uploader'])
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
            'data' => new ParameterContentRowResource($row->load(['status.doneBy', 'documents.versions', 'documents.uploader'])),
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
        $parameterContentRow->loadMissing(['documents.versions', 'documents.uploader']);

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
                $parameterContentRow->load(['status.doneBy', 'documents.versions', 'documents.uploader'])
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

    public function destroyRowDocuments(Request $request, ParameterContentRow $parameterContentRow)
    {
        $parameterContentRow->load('parameter.area', 'documents.versions');
        AreaEvidenceGate::assertCanManageEvidence($request->user(), $parameterContentRow->parameter?->area);

        $storage = app(EvidenceStorage::class);

        foreach ($parameterContentRow->documents as $document) {
            $this->authorize('delete', $document);
            $storage->deleteDirectory("documents/{$document->id}");
            $document->delete();
        }

        app(AreaProgressService::class)->clearDoneWithoutFiles($parameterContentRow->id);
        app(AreaProgressService::class)->refresh($parameterContentRow->parameter?->area);

        $parameterContentRow->unsetRelation('documents');
        $parameterContentRow->load(['status.doneBy', 'documents.versions', 'documents.uploader']);

        return response()->json([
            'success' => true,
            'message' => 'All files for this row were removed.',
            'data' => new ParameterContentRowResource($parameterContentRow),
        ]);
    }

    public function submitRow(Request $request, ParameterContentRow $parameterContentRow)
    {
        $parameterContentRow->load(['parameter.area', 'documents']);
        AreaEvidenceGate::assertCanManageEvidence($request->user(), $parameterContentRow->parameter?->area);

        if ($parameterContentRow->isSectionHeading()) {
            return response()->json([
                'success' => false,
                'message' => 'Section headings cannot be submitted.',
            ], 422);
        }

        if ($parameterContentRow->documents->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Upload at least one PDF before submitting this row.',
            ], 422);
        }

        $status = ParameterRowStatus::updateOrCreate(
            ['content_row_id' => $parameterContentRow->id],
            [
                'is_done' => true,
                'done_by' => $request->user()->id,
                'done_at' => now(),
            ]
        );

        app(AreaProgressService::class)->refresh($parameterContentRow->parameter?->area);

        $parameterContentRow->setRelation('status', $status->load('doneBy'));
        $parameterContentRow->load(['documents.versions', 'documents.uploader']);

        return response()->json([
            'success' => true,
            'message' => 'Row submitted.',
            'data' => new ParameterContentRowResource($parameterContentRow),
        ]);
    }

    /**
     * One AACCUP area per code, preferring the program's active cycle copy.
     *
     * @param  \Illuminate\Support\Collection<int, AccreditationArea>  $areas
     * @return \Illuminate\Support\Collection<int, AccreditationArea>
     */
    private function uniqueCatalogAreas($areas)
    {
        return $areas
            ->sortBy(function (AccreditationArea $area) {
                $activeId = (int) ($area->cycle?->program?->active_cycle_id ?? 0);
                $isActive = $activeId > 0 && (int) $area->cycle_id === $activeId;

                return sprintf(
                    '%03d-%d-%010d',
                    $this->areaNumber($area->code),
                    $isActive ? 0 : 1,
                    $area->id
                );
            })
            ->unique(fn (AccreditationArea $area) => (string) $area->code)
            ->sortBy(fn (AccreditationArea $area) => sprintf('%03d-%010d', $this->areaNumber($area->code), $area->id))
            ->values();
    }

    private function areaNumber(?string $code): int
    {
        return preg_match('/area-(\d+)/i', (string) $code, $matches) ? (int) $matches[1] : 999;
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

    private function assertCanViewInstitutionAreas(?User $user): void
    {
        if (! $user || ! ($user->isQA() || $user->isVPAA() || $user->isSuperAdmin())) {
            abort(403, 'Only QA or VPAA/DI may list all accreditation areas.');
        }
    }

    private function assertCanEditContent(?User $user): void
    {
        if (! $user || ! ($user->isVPAA() || $user->isSuperAdmin())) {
            abort(403, 'Only the VPAA/DI may edit parameter content.');
        }
    }
}
