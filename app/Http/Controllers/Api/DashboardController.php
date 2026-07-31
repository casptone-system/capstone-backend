<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\Task;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get comprehensive dashboard analytics.
     */
    public function index(Request $request)
    {
        // Optional filter by program_id
        $programId = $request->get('program_id');

        // --- Total Programs ---
        $totalProgramsQuery = Program::query();
        if ($programId) {
            $totalProgramsQuery->where('id', $programId);
        }
        $totalPrograms = $totalProgramsQuery->count();

        // --- Total Areas ---
        $totalAreasQuery = AccreditationArea::query();
        if ($programId) {
            $totalAreasQuery->whereHas('cycle.program', function ($q) use ($programId) {
                $q->where('id', $programId);
            });
        }
        $totalAreas = $totalAreasQuery->count();

        // --- Total Evidence (Documents) ---
        $totalEvidenceQuery = Document::query();
        if ($programId) {
            $totalEvidenceQuery->where('program_id', $programId);
        }
        $totalEvidence = $totalEvidenceQuery->count();

        // --- Compliance % ---
        // Areas with at least one document uploaded / total areas
        $areasWithEvidenceQuery = AccreditationArea::whereHas('documents');
        if ($programId) {
            $areasWithEvidenceQuery->whereHas('cycle.program', function ($q) use ($programId) {
                $q->where('id', $programId);
            });
        }
        $areasWithEvidence = $areasWithEvidenceQuery->count();
        $compliancePercent = $totalAreas > 0
            ? round(($areasWithEvidence / $totalAreas) * 100, 1)
            : 0;

        // --- Readiness % ---
        // Cycles with 'Ready' or 'Completed' status / total cycles
        $totalCyclesQuery = AccreditationCycle::query();
        if ($programId) {
            $totalCyclesQuery->where('program_id', $programId);
        }
        $totalCycles = $totalCyclesQuery->count();

        $readyCyclesQuery = AccreditationCycle::whereIn('status', ['Ready', 'Completed']);
        if ($programId) {
            $readyCyclesQuery->where('program_id', $programId);
        }
        $readyCycles = $readyCyclesQuery->count();
        $readinessPercent = $totalCycles > 0
            ? round(($readyCycles / $totalCycles) * 100, 1)
            : 0;

        // --- Pending Reviews ---
        // Reviews in non-terminal states (not Ready, not Rejected)
        $pendingReviewsQuery = Review::whereNotIn('current_status', ['Ready', 'Rejected']);
        if ($programId) {
            $pendingReviewsQuery->whereHas('cycle', function ($q) use ($programId) {
                $q->where('program_id', $programId);
            });
        }
        $pendingReviews = $pendingReviewsQuery->count();

        // --- Overdue Tasks ---
        // Tasks past due date that are not 'Completed'
        $overdueTasksQuery = Task::where('due_date', '<', now())
            ->where('status', '!=', 'Completed');
        if ($programId) {
            $overdueTasksQuery->whereHas('area.cycle.program', function ($q) use ($programId) {
                $q->where('id', $programId);
            });
        }
        $overdueTasks = $overdueTasksQuery->count();

        // --- Area Status Breakdown ---
        $areaStatuses = AccreditationArea::selectRaw('status, count(*) as count')
            ->when($programId, function ($q) use ($programId) {
                $q->whereHas('cycle.program', function ($q) use ($programId) {
                    $q->where('id', $programId);
                });
            })
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Cycle Status Breakdown ---
        $cycleStatuses = AccreditationCycle::selectRaw('status, count(*) as count')
            ->when($programId, function ($q) use ($programId) {
                $q->where('program_id', $programId);
            })
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Task Status Breakdown ---
        $taskStatuses = Task::selectRaw('status, count(*) as count')
            ->when($programId, function ($q) use ($programId) {
                $q->whereHas('area.cycle.program', function ($q) use ($programId) {
                    $q->where('id', $programId);
                });
            })
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Review Status Breakdown ---
        $reviewStatuses = Review::selectRaw('current_status as status, count(*) as count')
            ->when($programId, function ($q) use ($programId) {
                $q->whereHas('cycle', function ($q) use ($programId) {
                    $q->where('program_id', $programId);
                });
            })
            ->groupBy('current_status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Per-Program Breakdown (only when no program filter) ---
        $programBreakdown = null;
        if (!$programId) {
            $programs = Program::withCount([
                'accreditationCycles as cycles_count',
                'accreditationCycles as ready_cycles_count' => function ($q) {
                    $q->whereIn('status', ['Ready', 'Completed']);
                },
            ])->get();

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