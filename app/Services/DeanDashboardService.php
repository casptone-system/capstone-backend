<?php

namespace App\Services;

use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Task;
use App\Models\User;
use App\Support\ActiveCycle;
use App\Support\RoleSlug;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

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
                'issues' => [],
                'activity' => [],
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
            ->with(['program', 'uploader', 'area'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $documentCounts = Document::query()
            ->whereIn('program_id', $programIds)
            ->selectRaw('program_id, COUNT(*) as aggregate')
            ->groupBy('program_id')
            ->pluck('aggregate', 'program_id');

        $documentStatusRows = $programIds->isEmpty()
            ? collect()
            : Document::query()
                ->whereIn('program_id', $programIds)
                ->selectRaw('program_id, status, COUNT(*) as aggregate')
                ->groupBy('program_id', 'status')
                ->get()
                ->groupBy('program_id');

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
                'code' => $college->code,
                'campus' => $college->campus,
                'description' => $college->description,
            ],
            'stats' => [
                ['label' => 'Programs', 'value' => (string) $programs->count(), 'type' => 'programs'],
                ['label' => 'Overall Compliance', 'value' => round($avgCompliance, 1) . '%', 'type' => 'compliance'],
                ['label' => 'Pending Reviews', 'value' => (string) $pendingDocuments, 'type' => 'pending'],
                ['label' => 'At-Risk Programs', 'value' => (string) $atRiskPrograms, 'type' => 'risk'],
                ['label' => 'Faculty Participation', 'value' => $facultyCount ? round(($activeFacultyCount / $facultyCount) * 100, 1) . '%' : '0%', 'type' => 'faculty'],
                ['label' => 'Active Program Chairs', 'value' => (string) $activeProgramChairCount, 'type' => 'chairs'],
            ],
            'programs' => ($programRows = $programs->map(function ($program) use ($facultyByProgram, $documentCounts, $documentStatusRows) {
                $faculty = $facultyByProgram->get($program->id, collect())->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $this->displayName($user),
                        'email' => $user->email,
                    ];
                })->values()->all();

                $analytics = $this->requirementAnalytics($program);
                $currentCycle = ActiveCycle::forProgram($program);
                $accreditationLevel = $currentCycle?->level ?: ($program->accreditation_level ?: 'Not Set');
                $statusCounts = ($documentStatusRows->get($program->id) ?? collect())
                    ->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->aggregate])
                    ->all();

                return [
                    'id' => $program->id,
                    'name' => $program->name,
                    'code' => $program->code,
                    'chair' => $this->displayName($program->chairUser) ?: $program->chairUser?->name,
                    'needsChairAssigned' => (bool) $program->needs_chair_assigned,
                    'faculty' => $faculty,
                    'facultyCount' => count($faculty),
                    'accreditationStatus' => $program->accreditation_status,
                    'accreditationLevel' => $accreditationLevel,
                    'cycleStatus' => $currentCycle?->status,
                    'workflowStatus' => $currentCycle?->workflow_status,
                    'instrumentName' => $currentCycle?->instrument_name,
                    'scheduledVisit' => $currentCycle?->scheduled_visit?->toDateString(),
                    'validUntil' => $currentCycle?->valid_until?->toDateString(),
                    'complianceScore' => (int) $program->compliance_score,
                    'documentCount' => (int) ($documentCounts[$program->id] ?? 0),
                    'documentStatusCounts' => $statusCounts,
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
            })->values()),
            'pendingDocuments' => $documents->map(function ($document) {
                return [
                    'id' => $document->id,
                    'title' => $document->title,
                    'program' => $document->program?->name,
                    'programId' => $document->program_id,
                    'area' => $document->area?->name,
                    'submittedBy' => $this->displayName($document->uploader),
                    'status' => $document->status,
                    'submittedAt' => $document->created_at?->toIso8601String(),
                    'updatedAt' => $document->updated_at?->toIso8601String(),
                ];
            })->values(),
            'issues' => $this->buildIssues($programRows->all()),
            'activity' => $this->buildActivity($programIds),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $programs
     * @return array<int, array<string, mixed>>
     */
    private function buildIssues(array $programs): array
    {
        $issues = [];

        foreach ($programs as $program) {
            $name = (string) ($program['name'] ?? 'Program');
            $id = $program['id'] ?? null;
            $chair = trim((string) ($program['chair'] ?? ''));
            $owner = $chair !== '' ? $chair : 'Dean';
            $score = (int) ($program['complianceScore'] ?? 0);
            $requirements = collect($program['requirements'] ?? []);
            $areas = collect($program['areaProgress'] ?? []);

            if (! empty($program['needsChairAssigned']) || $chair === '') {
                $issues[] = $this->issue(
                    "chair-{$id}",
                    'high',
                    'Leadership',
                    "{$name} has no program chair",
                    'A chair has to own requirements, faculty assignments, and evidence review before this program can move through accreditation.',
                    $name,
                    $id,
                    'Dean',
                    'Before the cycle moves forward',
                    'Assign a program chair from Program Monitoring.'
                );
            }

            $level = (string) ($program['accreditationLevel'] ?? '');
            if ($level === '' || $level === 'Not Set') {
                $issues[] = $this->issue(
                    "level-{$id}",
                    'high',
                    'Accreditation cycle',
                    "{$name} has no active accreditation level",
                    'Without an open level, area work and the college readiness score have nothing to measure against.',
                    $name,
                    $id,
                    $owner,
                    'Before area work starts',
                    'Confirm the program’s accreditation level so the active cycle can be monitored.'
                );
            }

            if (($program['workflowStatus'] ?? null) === 'At Risk') {
                $issues[] = $this->issue(
                    "workflow-{$id}",
                    'high',
                    'Accreditation cycle',
                    "{$name} is marked at risk",
                    'The active cycle workflow is At Risk. The college should check what is blocking the handoff from the chair to faculty evidence.',
                    $name,
                    $id,
                    $owner,
                    'This cycle',
                    'Review the program with the chair and clear the blocker before the visit window.'
                );
            }

            $visit = $program['scheduledVisit'] ?? null;
            if ($visit) {
                $visitDate = Carbon::parse($visit)->startOfDay();
                if ($visitDate->isBetween(now()->startOfDay(), now()->addDays(45)->endOfDay()) && $score < 80) {
                    $issues[] = $this->issue(
                        "visit-{$id}",
                        'high',
                        'Visit readiness',
                        "{$name} visit is close and readiness is {$score}%",
                        'A survey visit is scheduled inside the next 45 days, and the program is still under 80% complete.',
                        $name,
                        $id,
                        $owner,
                        $visitDate->toFormattedDateString(),
                        'Ask the chair which open areas can be closed before the visit date.'
                    );
                }
            }

            $validUntil = $program['validUntil'] ?? null;
            if ($validUntil && Carbon::parse($validUntil)->endOfDay()->isPast()) {
                $issues[] = $this->issue(
                    "validity-{$id}",
                    'high',
                    'Accreditation standing',
                    "{$name} accreditation validity has lapsed",
                    'The recorded validity date is already past. The college record should be updated before the program is treated as currently accredited.',
                    $name,
                    $id,
                    'Dean',
                    Carbon::parse($validUntil)->toFormattedDateString(),
                    'Confirm the current level and validity with the accreditation cycle, then update the program record.'
                );
            }

            $overdue = $requirements->filter(fn ($requirement) => ! empty($requirement['isOverdue']))->values();
            if ($overdue->isNotEmpty()) {
                $sample = $overdue->take(3)->pluck('title')->filter()->implode('; ');
                $extra = $overdue->count() > 3 ? ' and '.($overdue->count() - 3).' more' : '';
                $issues[] = $this->issue(
                    "overdue-{$id}",
                    'high',
                    'Requirements',
                    "{$name} has {$overdue->count()} overdue requirement".($overdue->count() === 1 ? '' : 's'),
                    $sample !== '' ? "Past due: {$sample}{$extra}." : 'Requirements passed their due date and are still not completed.',
                    $name,
                    $id,
                    $owner,
                    'Past due',
                    'Follow up with the program chair so the missed requirements are completed or rescheduled.'
                );
            }

            $openPriority = $requirements->filter(function ($requirement) {
                $priority = (string) ($requirement['priority'] ?? '');
                $status = (string) ($requirement['status'] ?? '');

                return in_array($priority, ['High', 'Critical'], true)
                    && ! in_array($status, ['Completed', 'Approved'], true)
                    && empty($requirement['isOverdue']);
            })->values();
            if ($openPriority->isNotEmpty()) {
                $sample = $openPriority->take(3)->map(fn ($requirement) => trim(($requirement['priority'] ?? '').' · '.($requirement['title'] ?? 'Requirement')))->implode('; ');
                $issues[] = $this->issue(
                    "priority-{$id}",
                    'medium',
                    'Requirements',
                    "{$name} still has {$openPriority->count()} high-priority requirement".($openPriority->count() === 1 ? '' : 's') . ' open',
                    $sample,
                    $name,
                    $id,
                    $owner,
                    'Before the area is closed',
                    'Confirm the chair has an owner and a due date on each high-priority requirement.'
                );
            }

            $weakAreas = $areas
                ->filter(fn ($area) => (int) ($area['progressPercent'] ?? 0) < 50)
                ->sortBy(fn ($area) => (int) ($area['progressPercent'] ?? 0))
                ->values();
            if ($weakAreas->isNotEmpty()) {
                $names = $weakAreas->take(3)->map(function ($area) {
                    $label = $this->areaLabel($area);

                    return $label.' ('.(int) ($area['progressPercent'] ?? 0).'%)';
                })->implode(', ');
                $issues[] = $this->issue(
                    "areas-{$id}",
                    $score < 50 ? 'high' : 'medium',
                    'Area progress',
                    "{$name} has {$weakAreas->count()} area".($weakAreas->count() === 1 ? '' : 's').' under 50%',
                    "Lowest coverage: {$names}.",
                    $name,
                    $id,
                    $owner,
                    'Current cycle',
                    'Ask the chair which parameters in those areas are still missing evidence.'
                );
            } elseif ($areas->isEmpty() && ($level !== '' && $level !== 'Not Set')) {
                $issues[] = $this->issue(
                    "areas-empty-{$id}",
                    'medium',
                    'Area progress',
                    "{$name} has no area progress on the active cycle",
                    'The program has a level, but no area completion has been recorded yet.',
                    $name,
                    $id,
                    $owner,
                    'Current cycle',
                    'Check that the instrument areas were opened and assigned for this cycle.'
                );
            }

            if ((int) ($program['facultyCount'] ?? 0) === 0 && $chair !== '') {
                $issues[] = $this->issue(
                    "faculty-{$id}",
                    'medium',
                    'Faculty roster',
                    "{$name} has a chair but no faculty on the roster",
                    'Evidence work cannot be distributed until faculty are added to the program.',
                    $name,
                    $id,
                    $chair,
                    'Before evidence collection',
                    'Add faculty from Program Monitoring so area assignments have contributors.'
                );
            }

            $counts = $program['documentStatusCounts'] ?? [];
            $returned = (int) ($counts['Revision Requested'] ?? 0);
            if ($returned > 0) {
                $issues[] = $this->issue(
                    "revision-{$id}",
                    'medium',
                    'Evidence',
                    "{$name} has {$returned} file".($returned === 1 ? '' : 's').' returned for revision',
                    'Returned files stay out of the accepted accreditation file until the program resubmits them.',
                    $name,
                    $id,
                    $owner,
                    'Waiting on resubmission',
                    'Confirm with the chair that the returned evidence is being corrected and uploaded again.'
                );
            }

            $drafts = (int) ($counts['Draft'] ?? 0);
            if ($drafts > 0) {
                $issues[] = $this->issue(
                    "draft-{$id}",
                    'watch',
                    'Evidence',
                    "{$name} has {$drafts} draft file".($drafts === 1 ? '' : 's'),
                    'Draft uploads are saved in the system, but they are not yet part of the official evidence file.',
                    $name,
                    $id,
                    $owner,
                    'Not yet filed',
                    'Ask the chair to finish or remove drafts that should not be left in the program folder.'
                );
            }
        }

        $rank = ['high' => 0, 'medium' => 1, 'watch' => 2];
        usort($issues, function (array $left, array $right) use ($rank) {
            $severity = ($rank[$left['severity']] ?? 9) <=> ($rank[$right['severity']] ?? 9);
            if ($severity !== 0) {
                return $severity;
            }

            return strcmp((string) $left['program'], (string) $right['program']);
        });

        return $issues;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int|string>|array<int, int|string>  $programIds
     * @return array<int, array<string, mixed>>
     */
    private function buildActivity($programIds): array
    {
        $ids = collect($programIds)->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $events = collect();

        $documents = Document::query()
            ->whereIn('program_id', $ids)
            ->with(['program:id,name', 'uploader:id,first_name,middle_name,last_name', 'area:id,name'])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        foreach ($documents as $document) {
            $actor = $this->displayName($document->uploader) ?: 'A college user';
            $programName = $document->program?->name ?: 'A program';
            $areaName = $document->area?->name;
            $occurredAt = $document->updated_at ?? $document->created_at;
            $freshUpload = $document->created_at && $document->updated_at
                && $document->updated_at->diffInMinutes($document->created_at) < 2;
            $status = (string) ($document->status ?: 'Active');

            $events->push([
                'id' => 'document-'.$document->id,
                'kind' => 'evidence',
                'title' => $freshUpload
                    ? "{$actor} uploaded evidence for {$programName}"
                    : "{$programName} evidence was updated",
                'detail' => '“'.$document->title.'”'
                    .($areaName ? " in {$areaName}" : '')
                    ." is {$status}.",
                'program' => $programName,
                'actor' => $actor,
                'occurredAt' => $occurredAt?->toIso8601String(),
            ]);
        }

        $tasks = Task::query()
            ->where(function ($query) use ($ids) {
                $query->whereIn('program_id', $ids)
                    ->orWhereHas('area.cycle', fn ($cycle) => $cycle->whereIn('program_id', $ids));
            })
            ->with(['program:id,name', 'area:id,name', 'area.cycle.program:id,name'])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        foreach ($tasks as $task) {
            $programName = $task->program?->name
                ?: $task->area?->cycle?->program?->name
                ?: 'A program';
            $areaName = $task->area?->name;
            $due = $task->due_date ? ' Due '.$task->due_date->toFormattedDateString().'.' : '';

            $events->push([
                'id' => 'task-'.$task->id,
                'kind' => 'requirement',
                'title' => "{$programName} requirement is {$task->status}",
                'detail' => '“'.$task->title.'”'
                    .($areaName ? " · {$areaName}" : '')
                    .'.'
                    .$due,
                'program' => $programName,
                'actor' => 'Program requirement',
                'occurredAt' => $task->updated_at?->toIso8601String(),
            ]);
        }

        $cycles = AccreditationCycle::query()
            ->whereIn('program_id', $ids)
            ->with('program:id,name')
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get();

        foreach ($cycles as $cycle) {
            $programName = $cycle->program?->name ?: 'A program';
            $standing = $cycle->workflow_status ?: $cycle->status ?: 'In progress';

            $events->push([
                'id' => 'cycle-'.$cycle->id,
                'kind' => 'cycle',
                'title' => "{$programName} accreditation standing was updated",
                'detail' => trim(($cycle->level ?: 'Level not set').' · '.$standing.($cycle->instrument_name ? ' · '.$cycle->instrument_name : '')).'.',
                'program' => $programName,
                'actor' => 'Accreditation cycle',
                'occurredAt' => $cycle->updated_at?->toIso8601String(),
            ]);
        }

        return $events
            ->filter(fn ($event) => ! empty($event['occurredAt']))
            ->sortByDesc('occurredAt')
            ->unique('id')
            ->take(24)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function areaLabel(array $area): string
    {
        $code = (string) ($area['code'] ?? '');
        if (preg_match('/area-(\d+)/i', $code, $matches)) {
            return 'Area '.$matches[1];
        }

        return (string) ($area['label'] ?? $area['name'] ?? 'Area');
    }

    private function displayName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(preg_replace('/\s+/', ' ', sprintf(
            '%s %s %s',
            $user->first_name,
            $user->middle_name ?? '',
            $user->last_name
        )) ?? '');

        return $name !== '' ? $name : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(
        string $id,
        string $severity,
        string $category,
        string $title,
        string $detail,
        string $program,
        mixed $programId,
        string $owner,
        string $due,
        string $action,
    ): array {
        return [
            'id' => $id,
            'severity' => $severity,
            'category' => $category,
            'title' => $title,
            'detail' => $detail,
            'program' => $program,
            'programId' => $programId,
            'owner' => $owner,
            'due' => $due,
            'action' => $action,
        ];
    }
}
