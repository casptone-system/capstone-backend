<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\Task;
use App\Support\OrgScope;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get comprehensive dashboard analytics.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $requestedProgramId = $request->filled('program_id') ? (int) $request->get('program_id') : null;
        $visibleIds = OrgScope::visibleProgramIds($user);

        if ($requestedProgramId) {
            abort_unless(OrgScope::canSeeProgram($user, $requestedProgramId), 403, 'You are not allowed to view this program.');
            $programId = $requestedProgramId;
            $programIds = [$requestedProgramId];
        } elseif ($visibleIds === null) {
            $programId = null;
            $programIds = null;
        } else {
            $programId = count($visibleIds) === 1 ? $visibleIds[0] : null;
            $programIds = $visibleIds;
        }

        $constrainByPrograms = function ($query, string $column = 'id') use ($programId, $programIds) {
            if ($programId) {
                return $query->where($column, $programId);
            }
            if ($programIds !== null) {
                if ($programIds === []) {
                    return $query->whereRaw('0 = 1');
                }

                return $query->whereIn($column, $programIds);
            }

            return $query;
        };

        // --- Total Programs ---
        $totalProgramsQuery = Program::query();
        $totalProgramsQuery = $constrainByPrograms($totalProgramsQuery, 'id');
        $totalPrograms = $totalProgramsQuery->count();

        $visibleProgramIds = $programId ? [$programId] : $programIds;
        $limitToPrograms = function ($query, string $column = 'program_id') use ($visibleProgramIds) {
            if ($visibleProgramIds === null) {
                return $query;
            }
            if ($visibleProgramIds === []) {
                return $query->whereRaw('0 = 1');
            }

            return $query->whereIn($column, $visibleProgramIds);
        };

        // --- Total Areas ---
        $totalAreasQuery = AccreditationArea::query();
        if ($visibleProgramIds !== null) {
            $ids = $visibleProgramIds;
            $totalAreasQuery->whereHas('cycle', fn ($q) => $ids === [] ? $q->whereRaw('0 = 1') : $q->whereIn('program_id', $ids));
        }
        $totalAreas = $totalAreasQuery->count();

        // --- Total Evidence (Documents) ---
        $totalEvidenceQuery = $limitToPrograms(Document::query());
        $totalEvidence = $totalEvidenceQuery->count();

        // --- Compliance % ---
        $areasWithEvidenceQuery = AccreditationArea::whereHas('documents');
        if ($visibleProgramIds !== null) {
            $ids = $visibleProgramIds;
            $areasWithEvidenceQuery->whereHas('cycle', fn ($q) => $ids === [] ? $q->whereRaw('0 = 1') : $q->whereIn('program_id', $ids));
        }
        $areasWithEvidence = $areasWithEvidenceQuery->count();
        $compliancePercent = $totalAreas > 0
            ? round(($areasWithEvidence / $totalAreas) * 100, 1)
            : 0;

        // --- Readiness % ---
        $totalCyclesQuery = $limitToPrograms(AccreditationCycle::query());
        $totalCycles = $totalCyclesQuery->count();

        $readyCyclesQuery = $limitToPrograms(AccreditationCycle::whereIn('status', ['Ready', 'Completed']));
        $readyCycles = $readyCyclesQuery->count();
        $readinessPercent = $totalCycles > 0
            ? round(($readyCycles / $totalCycles) * 100, 1)
            : 0;

        // --- Pending Reviews ---
        $pendingReviewsQuery = Review::whereNotIn('current_status', ['Ready', 'Rejected']);
        if ($visibleProgramIds !== null) {
            $ids = $visibleProgramIds;
            $pendingReviewsQuery->whereHas('cycle', fn ($q) => $ids === [] ? $q->whereRaw('0 = 1') : $q->whereIn('program_id', $ids));
        }
        $pendingReviews = $pendingReviewsQuery->count();

        // --- Overdue Tasks ---
        $overdueTasksQuery = Task::where('due_date', '<', now())->where('status', '!=', 'Completed');
        if ($visibleProgramIds !== null) {
            $ids = $visibleProgramIds;
            $overdueTasksQuery->whereHas('area.cycle', fn ($q) => $ids === [] ? $q->whereRaw('0 = 1') : $q->whereIn('program_id', $ids));
        }
        $overdueTasks = $overdueTasksQuery->count();

        $scopeHas = function ($builder, string $relation, string $column = 'program_id') use ($visibleProgramIds) {
            if ($visibleProgramIds === null) {
                return $builder;
            }
            $ids = $visibleProgramIds;

            return $builder->whereHas($relation, fn ($q) => $ids === [] ? $q->whereRaw('0 = 1') : $q->whereIn($column, $ids));
        };

        // --- Area Status Breakdown ---
        $areaStatuses = $scopeHas(AccreditationArea::selectRaw('status, count(*) as count'), 'cycle')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Cycle Status Breakdown ---
        $cycleStatuses = $limitToPrograms(AccreditationCycle::selectRaw('status, count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Task Status Breakdown ---
        $taskStatuses = $scopeHas(Task::selectRaw('status, count(*) as count'), 'area.cycle')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Review Status Breakdown ---
        $reviewStatuses = $scopeHas(Review::selectRaw('current_status as status, count(*) as count'), 'cycle')
            ->groupBy('current_status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Per-Program Breakdown (only when no single-program filter) ---
        $programBreakdown = null;
        if (! $programId) {
            $programsQuery = Program::withCount([
                'accreditationCycles as cycles_count',
                'accreditationCycles as ready_cycles_count' => function ($q) {
                    $q->whereIn('status', ['Ready', 'Completed']);
                },
            ]);
            $programs = $limitToPrograms($programsQuery, 'id')->get();

            $programBreakdown = $programs->map(function ($program) {
                $programAreas = AccreditationArea::whereHas('cycle', function ($q) use ($program) {
                    $q->where('program_id', $program->id);
                })->count();

                $programEvidence = Document::where('program_id', $program->id)->count();

                $programOverdueTasks = Task::where('due_date', '<', now())
                    ->where('status', '!=', 'Completed')
                    ->whereHas('area.cycle.program', function ($q) use ($program) {
                        $q->where('id', $program->id);
                    })->count();

                $programPendingReviews = Review::whereNotIn('current_status', ['Ready', 'Rejected'])
                    ->whereHas('cycle', function ($q) use ($program) {
                        $q->where('program_id', $program->id);
                    })->count();

                return [
                    'programId' => $program->id,
                    'programName' => $program->name,
                    'programCode' => $program->code,
                    'totalAreas' => $programAreas,
                    'totalEvidence' => $programEvidence,
                    'overdueTasks' => $programOverdueTasks,
                    'pendingReviews' => $programPendingReviews,
                    'readinessPercent' => $program->cycles_count > 0
                        ? round(($program->ready_cycles_count / $program->cycles_count) * 100, 1)
                        : 0,
                ];
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Dashboard analytics retrieved successfully.',
            'data' => [
                'summary' => [
                    'totalPrograms' => $totalPrograms,
                    'totalAreas' => $totalAreas,
                    'totalEvidence' => $totalEvidence,
                    'totalCycles' => $totalCycles,
                    'compliancePercent' => $compliancePercent,
                    'readinessPercent' => $readinessPercent,
                    'pendingReviews' => $pendingReviews,
                    'overdueTasks' => $overdueTasks,
                    'areasWithEvidence' => $areasWithEvidence,
                ],
                'breakdowns' => [
                    'areaStatuses' => $areaStatuses,
                    'cycleStatuses' => $cycleStatuses,
                    'taskStatuses' => $taskStatuses,
                    'reviewStatuses' => $reviewStatuses,
                ],
                'programs' => $programBreakdown,
            ],
        ], 200);
    }
}