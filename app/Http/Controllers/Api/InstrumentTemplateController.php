<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstrumentTemplate;
use App\Models\InstrumentTemplateArea;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstrumentTemplateController extends Controller
{
    public function index(Request $request)
    {
        $this->assertCanViewTemplates($request->user());

        $templates = InstrumentTemplate::with(['areas.parameters.criteria'])
            ->orderBy('level')
            ->get();

        return response()->json(['success' => true, 'data' => $templates]);
    }

    public function show(Request $request, InstrumentTemplate $instrumentTemplate)
    {
        $this->assertCanViewTemplates($request->user());

        return response()->json([
            'success' => true,
            'data' => $instrumentTemplate->load('areas.parameters.criteria'),
        ]);
    }

    public function upsert(Request $request)
    {
        $this->assertCanManageTemplates($request->user());

        $validated = $request->validate([
            'id' => ['nullable', 'exists:instrument_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', 'in:Level I,Level II,Level III,Level IV'],
            'description' => ['nullable', 'string'],
            'areas' => ['required', 'array', 'min:1'],
            'areas.*.id' => ['nullable', 'integer'],
            'areas.*.name' => ['required', 'string', 'max:255'],
            'areas.*.description' => ['nullable', 'string'],
            'areas.*.parameters' => ['nullable', 'array'],
            'areas.*.parameters.*.id' => ['nullable', 'integer'],
            'areas.*.parameters.*.code' => ['required_with:areas.*.parameters', 'string', 'max:20'],
            'areas.*.parameters.*.name' => ['required_with:areas.*.parameters', 'string', 'max:255'],
            'areas.*.parameters.*.criteria' => ['nullable', 'array'],
            'areas.*.parameters.*.criteria.*.title' => ['required_with:areas.*.parameters.*.criteria', 'string', 'max:255'],
            'areas.*.parameters.*.criteria.*.description' => ['nullable', 'string'],
            'areas.*.parameters.*.criteria.*.evidence_type' => ['nullable', 'in:system,implementation,outcomes'],
        ]);

        $template = DB::transaction(function () use ($validated, $request) {
            $template = InstrumentTemplate::query()
                ->when($validated['id'] ?? null, fn ($query, $id) => $query->where('id', $id))
                ->when(! ($validated['id'] ?? null), fn ($query) => $query->where('level', $validated['level']))
                ->first();

            if (! $template) {
                $template = new InstrumentTemplate();
            }

            $template->fill([
                'name' => $validated['name'],
                'level' => $validated['level'],
                'description' => $validated['description'] ?? null,
                'is_active' => true,
                'updated_by' => $request->user()->id,
            ])->save();

            $template->areas()->delete();

            foreach ($validated['areas'] as $areaIndex => $areaData) {
                $area = $template->areas()->create([
                    'name' => $areaData['name'],
                    'description' => $areaData['description'] ?? null,
                    'sort_order' => $areaIndex,
                ]);

                foreach ($areaData['parameters'] ?? [] as $parameterIndex => $parameterData) {
                    $parameter = $area->parameters()->create([
                        'code' => $parameterData['code'],
                        'name' => $parameterData['name'],
                        'sort_order' => $parameterIndex,
                    ]);

                    foreach ($parameterData['criteria'] ?? [] as $criterionIndex => $criterionData) {
                        $parameter->criteria()->create([
                            'title' => $criterionData['title'],
                            'description' => $criterionData['description'] ?? null,
                            'evidence_type' => $criterionData['evidence_type'] ?? 'system',
                            'sort_order' => $criterionIndex,
                        ]);
                    }
                }
            }

            return $template->fresh('areas.parameters.criteria');
        });

        return response()->json([
            'success' => true,
            'message' => 'Template saved.',
            'data' => $template,
        ]);
    }

    public function destroyArea(Request $request, InstrumentTemplateArea $area)
    {
        $this->assertCanManageTemplates($request->user());
        $area->delete();

        return response()->json(['success' => true, 'message' => 'Area removed from template.']);
    }

    private function assertCanViewTemplates(?User $user): void
    {
        if (! $user || (! $user->isVPAA() && ! $user->isQA() && ! $user->isSuperAdmin())) {
            abort(403, 'Only QA or VPAA/DI can view accreditation templates.');
        }
    }

    private function assertCanManageTemplates(?User $user): void
    {
        if (! $user || (! $user->isVPAA() && ! $user->isSuperAdmin())) {
            abort(403, 'Only the VPAA/DI can manage accreditation templates.');
        }
    }
}
