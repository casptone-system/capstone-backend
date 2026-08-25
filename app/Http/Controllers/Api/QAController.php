<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Support\ActiveCycle;
use Illuminate\Http\Request;

class QAController extends Controller
{
    /**
     * Institution-wide QA monitoring. These endpoints are deliberately unscoped
     * across every college and program.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();

        // Verify user is QA
        if (!$user || !$user->isQA()) {
            abort(403, 'Only QA staff can access the QA dashboard.');
        }

        // --- Active Programs ---
        $activePrograms = AccreditationCycle::whereNotIn('status', ['Ready', 'Completed', 'Expired'])
            ->distinct('program_id')
            ->count('program_id');

        // --- Programs At Risk ---
        $atRiskPrograms = AccreditationCycle::whereIn('phase', ['VPAA Monitoring', 'At Risk'])
            ->orWhere('status', 'Internal Review')
            ->distinct('program_id')
            ->count('program_id');

        // --- Evidence Completion % ---
        $totalAreas = AccreditationArea::count();
        $areasWithEvidence = AccreditationArea::whereHas('documents')->count();
        $evidenceCompletion = $totalAreas > 0 ? round(($areasWithEvidence / $totalAreas) * 100) : 0;

        // --- Pending Reviews ---
        $pendingReviews = Review::whereNotIn('current_status', ['Ready', 'Rejected'])->count();

        // --- Programs List with Status ---
        $programs = AccreditationCycle::with('program.college')
            ->distinct()
            ->orderBy('updated_at', 'desc')
            ->get()
            ->unique('program_id')
            ->map(function ($cycle) {
                $program = $cycle->program;
                $college = $program?->college;
                $totalAreas = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))->count();
                $areasWithEvidence = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))
                    ->whereHas('documents')
                    ->count();
                $readiness = $totalAreas > 0 ? round(($areasWithEvidence / $totalAreas) * 100) : 0;

                return [
                    'id' => $cycle->id,
                    'program_id' => $program?->id,
                    'program_name' => $program?->name ?? 'Unknown Program',
                    'college_id' => $college?->id,
                    'college_name' => $college?->name ?? 'Unknown College',
                    'level' => $cycle->level,
                    'phase' => $cycle->phase ?? 'Initial Notice',
                    'status' => $cycle->status,
                    'readiness' => $readiness,
                    'readiness_status' => $readiness >= 80 ? 'On Track' : ($readiness >= 50 ? 'In Progress' : 'At Risk'),
                    'evidence_items' => $areasWithEvidence,
                    'total_areas' => $totalAreas,
                    'scheduled_visit' => $cycle->scheduled_visit?->toDateString(),
                    'valid_until' => $cycle->valid_until?->toDateString(),
                    'created_at' => $cycle->created_at?->toDateTimeString(),
                    'updated_at' => $cycle->updated_at?->toDateTimeString(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'metrics' => [
                    'active_programs' => $activePrograms,
                    'at_risk_programs' => $atRiskPrograms,
                    'evidence_completion' => (int)$evidenceCompletion,
                    'pending_reviews' => $pendingReviews,
                ],
                'programs' => $programs,
            ],
        ], 200);
    }

    /**
     * Get program readiness report
     */
    public function programReadinessReport(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->isQA()) {
            abort(403, 'Only QA staff can access reports.');
        }

        $programs = Program::with('college')
            ->get()
            ->map(function ($program) {
                $cycle = AccreditationCycle::where('program_id', $program->id)->latest()->first();
                $totalAreas = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))->count();
                $completedAreas = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))
                    ->whereHas('documents')
                    ->count();
                $readiness = $totalAreas > 0 ? round(($completedAreas / $totalAreas) * 100) : 0;

                return [
                    'program_id' => $program->id,
                    'program_name' => $program->name,
                    'college_name' => $program->college?->name,
                    'level' => $cycle?->level,
                    'phase' => $cycle?->phase ?? 'Not Started',
                    'total_requirements' => $totalAreas,
                    'completed' => $completedAreas,
                    'pending' => $totalAreas - $completedAreas,
                    'returned' => 0, // Calculate if you track returned evidence
                    'readiness_percent' => (int)$readiness,
                ];
            })
            ->sortByDesc('readiness_percent');

        return response()->json([
            'success' => true,
            'data' => [
                'report_type' => 'Program Readiness',
                'generated_at' => now()->toDateTimeString(),
                'programs' => $programs->values(),
            ],
        ], 200);
    }

    /**
     * Get college comparison report
     */
    public function collegeComparisonReport(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->isQA()) {
            abort(403, 'Only QA staff can access reports.');
        }

        $colleges = \App\Models\College::get()
            ->map(function ($college) {
                $programs = Program::where('college_id', $college->id)->get();
                $totalAreas = 0;
                $completedAreas = 0;

                foreach ($programs as $program) {
                    $cycleAreas = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))->count();
                    $cycleCompleted = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))
                        ->whereHas('documents')
                        ->count();
                    $totalAreas += $cycleAreas;
                    $completedAreas += $cycleCompleted;
                }

                $readiness = $totalAreas > 0 ? round(($completedAreas / $totalAreas) * 100) : 0;

                return [
                    'college_id' => $college->id,
                    'college_name' => $college->name,
                    'program_count' => $programs->count(),
                    'total_requirements' => $totalAreas,
                    'completed_requirements' => $completedAreas,
                    'readiness_percent' => (int)$readiness,
                ];
            })
            ->sortByDesc('readiness_percent');

        return response()->json([
            'success' => true,
            'data' => [
                'report_type' => 'College Comparison',
                'generated_at' => now()->toDateTimeString(),
                'colleges' => $colleges->values(),
            ],
        ], 200);
    }

    /**
     * Get at-risk programs report
     */
    public function atRiskProgramsReport(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->isQA()) {
            abort(403, 'Only QA staff can access reports.');
        }

        $threshold = $request->get('threshold', 70); // Programs below 70% are at risk

        $atRiskPrograms = Program::with('college')
            ->get()
            ->map(function ($program) {
                $cycle = AccreditationCycle::where('program_id', $program->id)->latest()->first();
                $totalAreas = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))->count();
                $completedAreas = AccreditationArea::whereHas('cycle', fn ($q) => $q->where('program_id', $program->id))
                    ->whereHas('documents')
                    ->count();
                $readiness = $totalAreas > 0 ? round(($completedAreas / $totalAreas) * 100) : 0;

                return [
                    'program_id' => $program->id,
                    'program_name' => $program->name,
                    'college_name' => $program->college?->name,
                    'level' => $cycle?->level,
                    'phase' => $cycle?->phase ?? 'Not Started',
                    'readiness_percent' => (int)$readiness,
                    'completed' => $completedAreas,
                    'pending' => $totalAreas - $completedAreas,
                    'total' => $totalAreas,
                    'risk_level' => $readiness < 30 ? 'Critical' : ($readiness < 50 ? 'High' : 'Medium'),
                ];
            })
            ->filter(fn ($p) => $p['readiness_percent'] < $threshold)
            ->sortBy('readiness_percent')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'report_type' => 'At-Risk Programs',
                'threshold' => $threshold,
                'generated_at' => now()->toDateTimeString(),
                'at_risk_count' => $atRiskPrograms->count(),
                'programs' => $atRiskPrograms,
            ],
        ], 200);
    }

    /**
     * Get accreditation program list for QA viewing
     */
    public function accreditationPrograms(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->isQA()) {
            abort(403, 'Only QA staff can access accreditation data.');
        }

        $cycles = ActiveCycle::uniquePerProgram(
            AccreditationCycle::with(['program.college', 'program.chairUser'])
                ->orderByDesc('updated_at')
                ->get()
        );

        $page = max(1, (int) $request->get('page', 1));
        $perPage = max(1, (int) $request->get('per_page', 15));
        $paged = $cycles->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $paged,
                'total' => $cycles->count(),
                'per_page' => $perPage,
                'current_page' => $page,
            ],
        ], 200);
    }

    /**
     * Get accreditation cycle detail for QA
     */
    public function accreditationDetail(Request $request, AccreditationCycle $cycle)
    {
        $user = $request->user();

        if (!$user || !$user->isQA()) {
            abort(403, 'Only QA staff can view accreditation details.');
        }

        $program = $cycle->program;
        $areas = AccreditationArea::where('accreditation_cycle_id', $cycle->id)
            ->with('documents')
            ->get()
            ->map(function ($area) {
                return [
                    'id' => $area->id,
                    'area_name' => $area->area_name,
                    'description' => $area->description,
                    'documents_count' => $area->documents->count(),
                    'created_at' => $area->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'accreditation_cycle' => $cycle,
                'program' => $program,
                'college' => $program?->college,
                'areas' => $areas,
                'total_areas' => $areas->count(),
                'areas_with_evidence' => $areas->filter(fn ($a) => $a['documents_count'] > 0)->count(),
            ],
        ], 200);
    }
}
