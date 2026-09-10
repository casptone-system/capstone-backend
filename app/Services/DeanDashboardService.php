<?php

namespace App\Services;

use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Task;
use App\Models\User;
use App\Support\ActiveCycle;
use App\Support\RoleSlug;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DeanDashboardService
{
    public function __construct(private AreaProgressService $progress)
    {
    }

    public function resolveCollege(User $user, ?int $requestedCollegeId = null): ?College
    {
        $collegeId = $user->college_id;
        if (! $collegeId) {
            return null;
        }

        if ($requestedCollegeId && (int) $requestedCollegeId !== (int) $collegeId) {
            return null;
        }

        return College::find($collegeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function requirementAnalytics(Program $program): array
    {
        $tasks = Task::with(['area'])
            ->withCount('documents')
            ->whereHas('area.cycle', fn ($query) => $query->where('program_id', $program->id))
            ->orderBy('created_at', 'desc')
            ->get();

        $totalTasks = $tasks->count();
        $completedTasks = $tasks->filter(fn ($task) => $task->status === 'Completed')->count();
        $inProgressTasks = $tasks->filter(fn ($task) => in_array($task->status, ['In Progress', 'Not Started'], true))->count();
        $overdueTasks = $tasks->filter(fn ($task) => $task->due_date && $task->due_date->isPast() && $task->status !== 'Completed')->count();
        $completionRate = $totalTasks > 0 ? (int) round(($completedTasks / $totalTasks) * 100) : 0;

        $requirements = $tasks->map(function (Task $task) {
            $isOverdue = (bool) ($task->due_date && $task->due_date->isPast() && $task->status !== 'Completed');

            return [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'area' => $task->area?->name,
                'documentCount' => (int) ($task->documents_count ?? 0),
                'dueDate' => $task->due_date?->toDateString(),
                'isOverdue' => $isOverdue,
            ];
        })->values()->all();

        return [
            'totalTasks' => $totalTasks,
            'completedTasks' => $completedTasks,
            'inProgressTasks' => $inProgressTasks,
            'overdueTasks' => $overdueTasks,
            'completionRate' => $completionRate,
            'requirements' => $requirements,
        ];
    }

    public function areaProgress(Program $program): array
    {
        return $this->progress->breakdownForProgram($program);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user, ?int $requestedCollegeId = null): array
    {
        $college = $this->resolveCollege($user, $requestedCollegeId);

        if ($requestedCollegeId && ! $college) {
            throw (new ModelNotFoundException)->setModel(College::class, [$requestedCollegeId]);
        }

        if (! $college) {
            return [
                'stats' => [],
                'programs' => [],
                'pendingDocuments' => [],
            ];
        }

        $programs = Program::where('college_id', $college->id)
            ->with(['college', 'chairUser', 'accreditationCycles', 'activeCycle'])
            ->get();

        $programIds = $programs->pluck('id');

        $facultyByProgram = User::whereIn('program_id', $programIds)
            ->whereHas('roles', fn ($query) => $query->where('name', RoleSlug::FACULTY))
            ->select(['id', 'first_name', 'middle_name', 'last_name', 'email', 'program_id', 'email_verified_at'])
            ->get();

        $documents = Document::whereIn('program_id', $programIds)
            ->with(['program', 'uploader'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $documentCounts = Document::query()
            ->whereIn('program_id', $programIds)
            ->selectRaw('program_id, COUNT(*) as aggregate')
            ->groupBy('program_id')
            ->pluck('aggregate', 'program_id');

        $activeProgramChairCount = Program::where('college_id', $college->id)
            ->whereNotNull('chair_id')
            ->distinct('chair_id')
            ->count('chair_id');

        $facultyCount = $facultyByProgram->count();
        $activeFacultyCount = $facultyByProgram->whereNotNull('email_verified_at')->count();
        $facultyByProgram = $facultyByProgram->groupBy('program_id');

        $avgCompliance = $programs->avg('compliance_score') ?? 0;
        $pendingDocuments = $documents->filter(fn ($document) => $document->status !== 'Archived')->count();
        $atRiskPrograms = $programs->filter(fn ($program) => (int) $program->compliance_score < 70)->count();

        return [
            'dean' => [
                'id' => $user->id,
                'name' => trim(sprintf('%s %s %s', $user->first_name, $user->middle_name ?? '', $user->last_name)),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'position' => 'Dean',
                'role' => 'Dean',
                'department' => $college->name,
            ],
            'college' => [
                'id' => $college->id,
                'name' => $college->name,
            ],
            'stats' => [
                ['label' => 'Programs', 'value' => (string) $programs->count(), 'type' => 'programs'],
                ['label' => 'Overall Compliance', 'value' => round($avgCompliance, 1) . '%', 'type' => 'compliance'],
                ['label' => 'Pending Reviews', 'value' => (string) $pendingDocuments, 'type' => 'pending'],
                ['label' => 'At-Risk Programs', 'value' => (string) $atRiskPrograms, 'type' => 'risk'],
                ['label' => 'Faculty Participation', 'value' => $facultyCount ? round(($activeFacultyCount / $facultyCount) * 100, 1) . '%' : '0%', 'type' => 'faculty'],
                ['label' => 'Active Program Chairs', 'value' => (string) $activeProgramChairCount, 'type' => 'chairs'],
            ],
            'programs' => $programs->map(function ($program) use ($facultyByProgram, $documentCounts) {
                $faculty = $facultyByProgram->get($program->id, collect())->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => trim(sprintf('%s %s %s', $user->first_name, $user->middle_name ?? '', $user->last_name)),
                        'email' => $user->email,
                    ];
                })->values()->all();

                $analytics = $this->requirementAnalytics($program);
                $currentCycle = ActiveCycle::forProgram($program);
                $accreditationLevel = $currentCycle?->level ?? 'Not Set';

                return [
                    'id' => $program->id,
                    'name' => $program->name,
                    'code' => $program->code,
                    'chair' => $program->chairUser?->name,
                    'needsChairAssigned' => $program->needs_chair_assigned,
                    'faculty' => $faculty,
                    'facultyCount' => count($faculty),
                    'accreditationStatus' => $program->accreditation_status,
                    'accreditationLevel' => $accreditationLevel,
                    'complianceScore' => (int) $program->compliance_score,
                    'documentCount' => (int) ($documentCounts[$program->id] ?? 0),
                    'requirementProgress' => [
                        'totalTasks' => $analytics['totalTasks'],
                        'completedTasks' => $analytics['completedTasks'],
                        'inProgressTasks' => $analytics['inProgressTasks'],
                        'overdueTasks' => $analytics['overdueTasks'],
                        'completionRate' => $analytics['completionRate'],
                    ],
                    'areaProgress' => $this->progress->breakdownForProgram($program),
                    'requirements' => $analytics['requirements'],
                ];
            })->values(),
            'pendingDocuments' => $documents->map(function ($document) {
                return [
                    'id' => $document->id,
                    'title' => $document->title,
                    'program' => $document->program?->name,
                    'submittedBy' => $document->uploader?->name,
                    'status' => $document->status,
                    'submittedAt' => $document->created_at?->toIso8601String(),
                ];
            })->values(),
        ];
    }
}
