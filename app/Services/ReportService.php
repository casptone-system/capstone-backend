<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportService
{
    /**
     * Generate a compliance report across all programs and areas.
     *
     * @param array $filters Optional filters (program_id, college_id, cycle_id)
     * @return array
     */
    public function complianceReport(array $filters = []): array
    {
        $programId = $filters['program_id'] ?? null;
        $collegeId = $filters['college_id'] ?? null;
        $cycleId = $filters['cycle_id'] ?? null;

        // --- Build base queries with filters ---
        $areasQuery = AccreditationArea::query()
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->when($programId, fn($q) => $q->whereHas('cycle', fn($q2) => $q2->where('program_id', $programId)))
            ->when($collegeId, fn($q) => $q->whereHas('cycle.program', fn($q2) => $q2->where('college_id', $collegeId)));

        $totalAreas = (clone $areasQuery)->count();
        $areasWithEvidence = (clone $areasQuery)->whereHas('documents')->count();
        $areasCompleted = (clone $areasQuery)->where('status', 'Completed')->count();
        $areasInProgress = (clone $areasQuery)->where('status', 'In Progress')->count();
        $areasNotStarted = (clone $areasQuery)->where('status', 'Not Started')->count();

        $documentsQuery = Document::query()
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->when($cycleId, fn($q) => $q->whereHas('area', fn($q2) => $q2->where('cycle_id', $cycleId)))
            ->when($collegeId, fn($q) => $q->whereHas('program', fn($q2) => $q2->where('college_id', $collegeId)));

        $totalDocuments = (clone $documentsQuery)->count();
        $activeDocuments = (clone $documentsQuery)->where('status', 'Active')->count();
        $archivedDocuments = (clone $documentsQuery)->where('status', 'Archived')->count();

        $tasksQuery = Task::query()
            ->when($cycleId, fn($q) => $q->whereHas('area', fn($q2) => $q2->where('cycle_id', $cycleId)))
            ->when($programId, fn($q) => $q->whereHas('area.cycle', fn($q2) => $q2->where('program_id', $programId)))
            ->when($collegeId, fn($q) => $q->whereHas('area.cycle.program', fn($q2) => $q2->where('college_id', $collegeId)));

        $totalTasks = (clone $tasksQuery)->count();
        $completedTasks = (clone $tasksQuery)->where('status', 'Completed')->count();
        $overdueTasks = (clone $tasksQuery)->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();

        $reviewsQuery = Review::query()
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->when($programId, fn($q) => $q->whereHas('cycle', fn($q2) => $q2->where('program_id', $programId)))
            ->when($collegeId, fn($q) => $q->whereHas('cycle.program', fn($q2) => $q2->where('college_id', $collegeId)));

        $totalReviews = (clone $reviewsQuery)->count();
        $pendingReviews = (clone $reviewsQuery)->whereNotIn('current_status', ['Ready', 'Rejected'])->count();
        $approvedReviews = (clone $reviewsQuery)->where('current_status', 'Ready')->count();
        $rejectedReviews = (clone $reviewsQuery)->where('current_status', 'Rejected')->count();

        $compliancePercent = $totalAreas > 0 ? round(($areasWithEvidence / $totalAreas) * 100, 1) : 0;

        // --- Per-area compliance breakdown ---
        $areas = (clone $areasQuery)->with(['cycle.program', 'chair', 'documents'])
            ->orderBy('name')
            ->get();

        $areaBreakdown = $areas->map(function ($area) {
            $docCount = $area->documents->count();
            return [
                'areaId' => $area->id,
                'areaName' => $area->name,
                'areaStatus' => $area->status,
                'programName' => $area->cycle?->program?->name ?? 'N/A',
                'programCode' => $area->cycle?->program?->code ?? 'N/A',
                'chairName' => $area->chair?->name ?? 'Unassigned',
                'documentCount' => $docCount,
                'hasEvidence' => $docCount > 0,
                'complianceLevel' => $docCount > 0 ? 'Compliant' : 'Non-Compliant',
            ];
        });

        return [
            'reportType' => 'Compliance Report',
            'generatedAt' => now()->toDateTimeString(),
            'filters' => array_filter([
                'programId' => $programId ? (int) $programId : null,
                'collegeId' => $collegeId ? (int) $collegeId : null,
                'cycleId' => $cycleId ? (int) $cycleId : null,
            ]),
            'summary' => [
                'totalAreas' => $totalAreas,
                'areasWithEvidence' => $areasWithEvidence,
                'areasCompleted' => $areasCompleted,
                'areasInProgress' => $areasInProgress,
                'areasNotStarted' => $areasNotStarted,
                'totalDocuments' => $totalDocuments,
                'activeDocuments' => $activeDocuments,
                'archivedDocuments' => $archivedDocuments,
                'totalTasks' => $totalTasks,
                'completedTasks' => $completedTasks,
                'overdueTasks' => $overdueTasks,
                'totalReviews' => $totalReviews,
                'pendingReviews' => $pendingReviews,
                'approvedReviews' => $approvedReviews,
                'rejectedReviews' => $rejectedReviews,
                'compliancePercent' => $compliancePercent,
            ],
            'areas' => $areaBreakdown,
        ];
    }

    /**
     * Generate a detailed program report.
     */
    public function programReport(int $programId): array
    {
        $program = Program::with(['college', 'chairUser'])->findOrFail($programId);

        $cycles = AccreditationCycle::where('program_id', $programId)
            ->with(['areas.chair', 'areas.documents', 'areas.members.user', 'areas.tasks.assignments.user'])
            ->orderBy('created_at', 'desc')
            ->get();

        $totalAreas = $cycles->sum(fn($cycle) => $cycle->areas->count());
        $totalDocuments = Document::where('program_id', $programId)->count();
        $totalTasks = Task::whereHas('area.cycle', fn($q) => $q->where('program_id', $programId))->count();
        $overdueTasks = Task::where('due_date', '<', now())
            ->where('status', '!=', 'Completed')
            ->whereHas('area.cycle', fn($q) => $q->where('program_id', $programId))
            ->count();
        $totalReviews = Review::whereHas('cycle', fn($q) => $q->where('program_id', $programId))->count();
        $pendingReviews = Review::whereNotIn('current_status', ['Ready', 'Rejected'])
            ->whereHas('cycle', fn($q) => $q->where('program_id', $programId))
            ->count();

        $areasWithEvidence = AccreditationArea::whereHas('cycle', fn($q) => $q->where('program_id', $programId))
            ->whereHas('documents')->count();
        $compliancePercent = $totalAreas > 0 ? round(($areasWithEvidence / $totalAreas) * 100, 1) : 0;

        $cycleBreakdown = $cycles->map(function ($cycle) {
            $cycleAreas = $cycle->areas;
            $cycleDocs = $cycleAreas->sum(fn($a) => $a->documents->count());
            $cycleTasks = $cycleAreas->sum(fn($a) => $a->tasks->count());
            $cycleOverdue = Task::where('due_date', '<', now())
                ->where('status', '!=', 'Completed')
                ->whereHas('area', fn($q) => $q->where('cycle_id', $cycle->id))
                ->count();

            return [
                'cycleId' => $cycle->id,
                'level' => $cycle->level,
                'status' => $cycle->status,
                'readiness' => $cycle->readiness,
                'validUntil' => $cycle->valid_until?->toDateString(),
                'scheduledVisit' => $cycle->scheduled_visit?->toDateString(),
                'remarks' => $cycle->remarks,
                'totalAreas' => $cycleAreas->count(),
                'totalDocuments' => $cycleDocs,
                'totalTasks' => $cycleTasks,
                'overdueTasks' => $cycleOverdue,
                'areas' => $cycleAreas->map(fn($area) => [
                    'areaId' => $area->id,
                    'areaName' => $area->name,
                    'areaStatus' => $area->status,
                    'chairName' => $area->chair?->name ?? 'Unassigned',
                    'documentCount' => $area->documents->count(),
                    'memberCount' => $area->members->count(),
                    'taskCount' => $area->tasks->count(),
                    'members' => $area->members->map(fn($m) => [
                        'name' => $m->user?->name ?? 'Unknown',
                        'role' => $m->role,
                    ]),
                ]),
            ];
        });

        return [
            'reportType' => 'Program Report',
            'generatedAt' => now()->toDateTimeString(),
            'program' => [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->code,
                'chair' => $program->chairUser?->name ?? $program->chair,
                'accreditationStatus' => $program->accreditation_status,
                'complianceScore' => $program->compliance_score,
                'collegeName' => $program->college?->name ?? 'N/A',
                'collegeCode' => $program->college?->code ?? 'N/A',
            ],
            'summary' => [
                'totalCycles' => $cycles->count(),
                'totalAreas' => $totalAreas,
                'totalDocuments' => $totalDocuments,
                'totalTasks' => $totalTasks,
                'overdueTasks' => $overdueTasks,
                'totalReviews' => $totalReviews,
                'pendingReviews' => $pendingReviews,
                'areasWithEvidence' => $areasWithEvidence,
                'compliancePercent' => $compliancePercent,
            ],
            'cycles' => $cycleBreakdown,
        ];
    }

    /**
     * Generate a detailed college report.
     */
    public function collegeReport(int $collegeId): array
    {
        $college = College::findOrFail($collegeId);

        $programs = Program::where('college_id', $collegeId)
            ->with(['accreditationCycles.areas.documents', 'chairUser'])
            ->orderBy('name')
            ->get();

        $totalPrograms = $programs->count();
        $totalCycles = $programs->sum(fn($p) => $p->accreditationCycles->count());
        $totalAreas = $programs->sum(fn($p) => $p->accreditationCycles->sum(fn($c) => $c->areas->count()));
        $totalDocuments = Document::whereHas('program', fn($q) => $q->where('college_id', $collegeId))->count();
        $totalTasks = Task::whereHas('area.cycle.program', fn($q) => $q->where('college_id', $collegeId))->count();
        $overdueTasks = Task::where('due_date', '<', now())
            ->where('status', '!=', 'Completed')
            ->whereHas('area.cycle.program', fn($q) => $q->where('college_id', $collegeId))
            ->count();
        $totalReviews = Review::whereHas('cycle.program', fn($q) => $q->where('college_id', $collegeId))->count();
        $pendingReviews = Review::whereNotIn('current_status', ['Ready', 'Rejected'])
            ->whereHas('cycle.program', fn($q) => $q->where('college_id', $collegeId))
            ->count();

        $areasWithEvidence = AccreditationArea::whereHas('cycle.program', fn($q) => $q->where('college_id', $collegeId))
            ->whereHas('documents')->count();
        $compliancePercent = $totalAreas > 0 ? round(($areasWithEvidence / $totalAreas) * 100, 1) : 0;

        $programBreakdown = $programs->map(function ($program) {
            $programAreas = $program->accreditationCycles->sum(fn($c) => $c->areas->count());
            $programDocs = $program->accreditationCycles->sum(fn($c) => $c->areas->sum(fn($a) => $a->documents->count()));
            $programTasks = Task::whereHas('area.cycle', fn($q) => $q->where('program_id', $program->id))->count();
            $programOverdue = Task::where('due_date', '<', now())
                ->where('status', '!=', 'Completed')
                ->whereHas('area.cycle', fn($q) => $q->where('program_id', $program->id))
                ->count();
            $programReviews = Review::whereHas('cycle', fn($q) => $q->where('program_id', $program->id))->count();
            $programPendingReviews = Review::whereNotIn('current_status', ['Ready', 'Rejected'])
                ->whereHas('cycle', fn($q) => $q->where('program_id', $program->id))
                ->count();
            $programAreasWithEvidence = AccreditationArea::whereHas('cycle', fn($q) => $q->where('program_id', $program->id))
                ->whereHas('documents')->count();
            $programCompliance = $programAreas > 0 ? round(($programAreasWithEvidence / $programAreas) * 100, 1) : 0;

            return [
                'programId' => $program->id,
                'programName' => $program->name,
                'programCode' => $program->code,
                'chair' => $program->chairUser?->name ?? $program->chair,
                'accreditationStatus' => $program->accreditation_status,
                'complianceScore' => $program->compliance_score,
                'totalCycles' => $program->accreditationCycles->count(),
                'totalAreas' => $programAreas,
                'totalDocuments' => $programDocs,
                'totalTasks' => $programTasks,
                'overdueTasks' => $programOverdue,
                'totalReviews' => $programReviews,
                'pendingReviews' => $programPendingReviews,
                'compliancePercent' => $programCompliance,
            ];
        });

        return [
            'reportType' => 'College Report',
            'generatedAt' => now()->toDateTimeString(),
            'college' => [
                'id' => $college->id,
                'name' => $college->name,
                'code' => $college->code,
                'description' => $college->description,
            ],
            'summary' => [
                'totalPrograms' => $totalPrograms,
                'totalCycles' => $totalCycles,
                'totalAreas' => $totalAreas,
                'totalDocuments' => $totalDocuments,
                'totalTasks' => $totalTasks,
                'overdueTasks' => $overdueTasks,
                'totalReviews' => $totalReviews,
                'pendingReviews' => $pendingReviews,
                'areasWithEvidence' => $areasWithEvidence,
                'compliancePercent' => $compliancePercent,
            ],
            'programs' => $programBreakdown,
        ];
    }

    /**
     * Generate a detailed area report.
     */
    public function areaReport(int $areaId): array
    {
        $area = AccreditationArea::with(['cycle.program.college', 'chair', 'members.user', 'documents.uploader', 'documents.versions', 'tasks.assignments.user', 'tasks.creator'])
            ->findOrFail($areaId);

        $documents = $area->documents;
        $tasks = $area->tasks;
        $members = $area->members;
        $reviews = Review::where('area_id', $areaId)->with('submitter', 'comments')->get();

        $completedTasks = $tasks->where('status', 'Completed')->count();
        $overdueTasks = $tasks->filter(fn($t) => $t->due_date < now() && $t->status !== 'Completed')->count();
        $pendingReviews = $reviews->whereNotIn('current_status', ['Ready', 'Rejected'])->count();

        return [
            'reportType' => 'Area Report',
            'generatedAt' => now()->toDateTimeString(),
            'area' => [
                'id' => $area->id,
                'name' => $area->name,
                'description' => $area->description,
                'status' => $area->status,
                'chairName' => $area->chair?->name ?? 'Unassigned',
                'chairEmail' => $area->chair?->email ?? 'N/A',
                'programName' => $area->cycle?->program?->name ?? 'N/A',
                'programCode' => $area->cycle?->program?->code ?? 'N/A',
                'collegeName' => $area->cycle?->program?->college?->name ?? 'N/A',
                'cycleLevel' => $area->cycle?->level ?? 'N/A',
                'cycleStatus' => $area->cycle?->status ?? 'N/A',
            ],
            'summary' => [
                'totalDocuments' => $documents->count(),
                'activeDocuments' => $documents->where('status', 'Active')->count(),
                'archivedDocuments' => $documents->where('status', 'Archived')->count(),
                'totalTasks' => $tasks->count(),
                'completedTasks' => $completedTasks,
                'overdueTasks' => $overdueTasks,
                'totalMembers' => $members->count(),
                'totalReviews' => $reviews->count(),
                'pendingReviews' => $pendingReviews,
                'compliancePercent' => $documents->count() > 0 ? 100 : 0,
            ],
            'members' => $members->map(fn($m) => [
                'name' => $m->user?->name ?? 'Unknown',
                'email' => $m->user?->email ?? 'N/A',
                'role' => $m->role,
            ]),
            'documents' => $documents->map(fn($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'description' => $d->description,
                'schoolYear' => $d->school_year,
                'status' => $d->status,
                'currentVersion' => $d->current_version,
                'uploadedBy' => $d->uploader?->name ?? 'Unknown',
                'versionCount' => $d->versions->count(),
                'createdAt' => $d->created_at?->toDateTimeString(),
            ]),
            'tasks' => $tasks->map(fn($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'priority' => $t->priority,
                'status' => $t->status,
                'dueDate' => $t->due_date?->toDateString(),
                'createdBy' => $t->creator?->name ?? 'Unknown',
                'assignees' => $t->assignments->map(fn($a) => [
                    'name' => $a->user?->name ?? 'Unknown',
                    'assignedAt' => $a->assigned_at?->toDateTimeString(),
                ]),
            ]),
            'reviews' => $reviews->map(fn($r) => [
                'id' => $r->id,
                'currentStatus' => $r->current_status,
                'submittedBy' => $r->submitter?->name ?? 'Unknown',
                'submittedAt' => $r->submitted_at?->toDateTimeString(),
                'completedAt' => $r->completed_at?->toDateTimeString(),
                'commentCount' => $r->comments->count(),
                'isTerminal' => $r->isTerminal(),
            ]),
        ];
    }

    /**
     * Generate a detailed accreditation cycle report.
     */
    public function accreditationReport(int $cycleId): array
    {
        $cycle = AccreditationCycle::with(['program.college', 'areas.chair', 'areas.members.user', 'areas.documents', 'areas.tasks'])
            ->findOrFail($cycleId);

        $areas = $cycle->areas;
        $totalAreas = $areas->count();
        $areasCompleted = $areas->where('status', 'Completed')->count();
        $areasInProgress = $areas->where('status', 'In Progress')->count();
        $areasNotStarted = $areas->where('status', 'Not Started')->count();
        $areasWithEvidence = $areas->filter(fn($a) => $a->documents->count() > 0)->count();

        $totalDocuments = $areas->sum(fn($a) => $a->documents->count());
        $totalTasks = Task::whereHas('area', fn($q) => $q->where('cycle_id', $cycleId))->count();
        $overdueTasks = Task::where('due_date', '<', now())
            ->where('status', '!=', 'Completed')
            ->whereHas('area', fn($q) => $q->where('cycle_id', $cycleId))
            ->count();
        $totalReviews = Review::where('cycle_id', $cycleId)->count();
        $pendingReviews = Review::where('cycle_id', $cycleId)
            ->whereNotIn('current_status', ['Ready', 'Rejected'])->count();

        $compliancePercent = $totalAreas > 0 ? round(($areasWithEvidence / $totalAreas) * 100, 1) : 0;
        $readinessPercent = $totalAreas > 0 ? round(($areasCompleted / $totalAreas) * 100, 1) : 0;

        $areaBreakdown = $areas->map(function ($area) {
            $areaDocs = $area->documents->count();
            $areaTasks = $area->tasks->count();
            $areaOverdue = Task::where('due_date', '<', now())
                ->where('status', '!=', 'Completed')
                ->where('area_id', $area->id)
                ->count();
            $areaReviews = Review::where('area_id', $area->id)->count();
            $areaPendingReviews = Review::where('area_id', $area->id)
                ->whereNotIn('current_status', ['Ready', 'Rejected'])->count();

            return [
                'areaId' => $area->id,
                'areaName' => $area->name,
                'areaDescription' => $area->description,
                'areaStatus' => $area->status,
                'chairName' => $area->chair?->name ?? 'Unassigned',
                'memberCount' => $area->members->count(),
                'documentCount' => $areaDocs,
                'taskCount' => $areaTasks,
                'overdueTasks' => $areaOverdue,
                'reviewCount' => $areaReviews,
                'pendingReviews' => $areaPendingReviews,
                'hasEvidence' => $areaDocs > 0,
                'members' => $area->members->map(fn($m) => [
                    'name' => $m->user?->name ?? 'Unknown',
                    'role' => $m->role,
                ]),
            ];
        });

        return [
            'reportType' => 'Accreditation Report',
            'generatedAt' => now()->toDateTimeString(),
            'cycle' => [
                'id' => $cycle->id,
                'level' => $cycle->level,
                'status' => $cycle->status,
                'readiness' => $cycle->readiness,
                'validUntil' => $cycle->valid_until?->toDateString(),
                'scheduledVisit' => $cycle->scheduled_visit?->toDateString(),
                'remarks' => $cycle->remarks,
                'programName' => $cycle->program?->name ?? 'N/A',
                'programCode' => $cycle->program?->code ?? 'N/A',
                'collegeName' => $cycle->program?->college?->name ?? 'N/A',
            ],
            'summary' => [
                'totalAreas' => $totalAreas,
                'areasCompleted' => $areasCompleted,
                'areasInProgress' => $areasInProgress,
                'areasNotStarted' => $areasNotStarted,
                'areasWithEvidence' => $areasWithEvidence,
                'totalDocuments' => $totalDocuments,
                'totalTasks' => $totalTasks,
                'overdueTasks' => $overdueTasks,
                'totalReviews' => $totalReviews,
                'pendingReviews' => $pendingReviews,
                'compliancePercent' => $compliancePercent,
                'readinessPercent' => $readinessPercent,
            ],
            'areas' => $areaBreakdown,
        ];
    }
}