<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\LevelParameterRow;
use App\Models\User;
use App\Services\LevelInstrumentCatalog;
use App\Support\AreaParameterCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LevelInstrumentController extends Controller
{
    public function areas(Request $request)
    {
        $this->assertCanView($request->user());
        $level = $this->level($request);
        LevelInstrumentCatalog::ensureSeeded($level);

        $areas = collect(AccreditationArea::AACCUP_AREAS)->map(function (array $area) {
            $pretty = trim((string) preg_replace('/^area\s*\d+\s*[–\-—:]\s*/iu', '', $area['name']));
            $number = null;
            if (preg_match('/area-(\d+)/i', $area['code'], $matches)) {
                $number = $matches[1];
            }

            return [
                'id' => $area['code'],
                'code' => $area['code'],
                'name' => $pretty !== '' ? $pretty : $area['name'],
                'label' => $number ? "AREA {$number} ({$pretty})" : $area['name'],
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $areas,
        ]);
    }

    public function parameters(Request $request, string $areaCode)
    {
        $this->assertCanView($request->user());
        $level = $this->level($request);
        $this->assertArea($areaCode);
        LevelInstrumentCatalog::ensureSeeded($level);

        $parameters = collect(AreaParameterCatalog::parameters()[$areaCode] ?? [])
            ->values()
            ->map(function (array $definition, int $index) use ($areaCode) {
                return [
                    'id' => $areaCode.'-'.$definition['code'],
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'label' => sprintf('Parameter %s: %s', $definition['code'], $definition['name']),
                    'sortOrder' => $index,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $parameters,
        ]);
    }

    public function rows(Request $request, string $areaCode, string $parameterCode)
    {
        $this->assertCanView($request->user());
        $level = $this->level($request);
        $this->assertParameter($areaCode, $parameterCode);
        LevelInstrumentCatalog::ensureSeeded($level);

        return response()->json([
            'success' => true,
            'data' => LevelInstrumentCatalog::rowsFor($level, $areaCode, $parameterCode)
                ->map(fn (LevelParameterRow $row) => $this->payload($row))
                ->values(),
        ]);
    }

    public function storeRow(Request $request, string $areaCode, string $parameterCode)
    {
        $this->assertCanEdit($request->user());
        $level = $this->level($request);
        $this->assertParameter($areaCode, $parameterCode);
        LevelInstrumentCatalog::ensureSeeded($level);

        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $row = DB::transaction(function () use ($request, $level, $areaCode, $parameterCode, $validated) {
            $next = (int) LevelParameterRow::query()
                ->where('level', $level)
                ->where('area_code', $areaCode)
                ->where('parameter_code', $parameterCode)
                ->max('sort_order');

            $created = LevelParameterRow::create([
                'level' => $level,
                'area_code' => $areaCode,
                'parameter_code' => $parameterCode,
                'content' => trim($validated['content']),
                'sort_order' => LevelParameterRow::query()
                    ->where('level', $level)
                    ->where('area_code', $areaCode)
                    ->where('parameter_code', $parameterCode)
                    ->exists() ? $next + 1 : 0,
                'updated_by' => $request->user()->id,
            ]);

            LevelInstrumentCatalog::publish($level, $areaCode, $parameterCode);

            return $created;
        });

        return response()->json([
            'success' => true,
            'message' => 'Parameter content row created.',
            'data' => $this->payload($row),
        ], 201);
    }

    public function updateRow(Request $request, LevelParameterRow $levelParameterRow)
    {
        $this->assertCanEdit($request->user());

        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $row = DB::transaction(function () use ($request, $levelParameterRow, $validated) {
            $levelParameterRow->update([
                'content' => trim($validated['content']),
                'updated_by' => $request->user()->id,
            ]);

            LevelInstrumentCatalog::publish(
                $levelParameterRow->level,
                $levelParameterRow->area_code,
                $levelParameterRow->parameter_code,
            );

            return $levelParameterRow->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Parameter content updated.',
            'data' => $this->payload($row),
        ]);
    }

    public function destroyRow(Request $request, LevelParameterRow $levelParameterRow)
    {
        $this->assertCanEdit($request->user());

        DB::transaction(function () use ($levelParameterRow) {
            $level = $levelParameterRow->level;
            $areaCode = $levelParameterRow->area_code;
            $parameterCode = $levelParameterRow->parameter_code;
            $levelParameterRow->delete();
            LevelInstrumentCatalog::publish($level, $areaCode, $parameterCode);
        });

        return response()->json([
            'success' => true,
            'message' => 'Parameter content row removed.',
        ]);
    }

    private function payload(LevelParameterRow $row): array
    {
        return [
            'id' => $row->id,
            'content' => $row->content,
            'sortOrder' => $row->sort_order,
            'level' => $row->level,
            'areaCode' => $row->area_code,
            'parameterCode' => $row->parameter_code,
            'isDone' => false,
            'hasFile' => false,
            'fileCount' => 0,
            'documents' => [],
            'commentCount' => 0,
            'unreadCommentCount' => 0,
            'canComment' => false,
        ];
    }

    private function level(Request $request): string
    {
        $level = (string) $request->input('level', '');

        if (! in_array($level, AccreditationCycle::LEVELS, true)) {
            abort(422, 'Choose Preliminary Level or Level 1 through Level 4.');
        }

        return $level;
    }

    private function assertArea(string $areaCode): void
    {
        $known = collect(AccreditationArea::AACCUP_AREAS)->contains(fn (array $area) => $area['code'] === $areaCode);

        if (! $known) {
            abort(404, 'Accreditation area not found.');
        }
    }

    /**
     * @return array{code: string, name: string}
     */
    private function assertParameter(string $areaCode, string $parameterCode): array
    {
        $this->assertArea($areaCode);

        $match = collect(AreaParameterCatalog::parameters()[$areaCode] ?? [])
            ->firstWhere('code', $parameterCode);

        if (! is_array($match)) {
            abort(404, 'Parameter not found.');
        }

        return $match;
    }

    private function assertCanView(?User $user): void
    {
        if (! $user || (! $user->isVPAA() && ! $user->isSuperAdmin())) {
            abort(403, 'Only the VPAA/DI may view level instruments.');
        }
    }

    private function assertCanEdit(?User $user): void
    {
        if (! $user || (! $user->isVPAA() && ! $user->isSuperAdmin())) {
            abort(403, 'Only the VPAA/DI may edit level instruments.');
        }
    }
}
