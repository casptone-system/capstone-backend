<?php

namespace App\Services;

use App\Models\AccreditationCycle;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\User;
use App\Notifications\VpaaCollegeFollowUpNotification;
use App\Support\ActiveCycle;
use App\Support\RoleSlug;
use Illuminate\Support\Collection;

class VpaaOversightService
{
    public function __construct(private AreaProgressService $progress)
    {
    }

    /**
     * Institutional snapshot used by the VPAA dashboard, readiness,
     * at-risk, reports, notifications, and activity log.
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        $cycles = ActiveCycle::uniquePerProgram(
            AccreditationCycle::with(['program.college', 'program.chairUser'])
                ->orderByDesc('scheduled_visit')
                ->orderByDesc('created_at')
                ->get()
        );

        $collegeIds = $cycles
            ->map(fn (AccreditationCycle $cycle) => $cycle->program?->college_id)
            ->filter()
            ->unique()
            ->values();

        $deans = User::query()
            ->whereIn('college_id', $collegeIds)
            ->whereHas('roles', fn ($query) => $query->where('name', RoleSlug::DEAN))
            ->orderBy('id')
            ->get()
            ->groupBy('college_id');

        $accreditations = $cycles
            ->map(fn (AccreditationCycle $cycle) => $this->serializeCycle($cycle, $deans))
            ->values();

        $atRisk = $accreditations->filter(fn (array $item) => $item['at_risk'])->values();
        $expiredValidity = $accreditations->filter(fn (array $item) => $item['validity_status'] === 'Expired')->values();
        $upcoming = $accreditations
            ->filter(fn (array $item) => $item['scheduled_visit'])
            ->sortBy('scheduled_visit')
            ->values();
        $workflowReady = $accreditations->filter(
            fn (array $item) => in_array($item['status'], ['Ready', 'Completed'], true)
        )->values();

        $evidenceScores = $accreditations->pluck('evidence_completion');
        $overall = $evidenceScores->isEmpty()
            ? 0
            : (int) round((float) $evidenceScores->avg());

        $summary = [
            'active_accreditations' => $accreditations->count(),
            'upcoming_accreditations' => $upcoming->filter(
                fn (array $item) => is_int($item['days_until_visit']) && $item['days_until_visit'] >= 0
            )->count(),
            'ready_programs' => $workflowReady->count(),
            'at_risk_programs' => $atRisk->count(),
            'expired_validity' => $expiredValidity->count(),
            'overall_readiness' => $overall,
            'evidence_ready' => $accreditations->where('evidence_band', 'Ready')->count(),
            'evidence_on_track' => $accreditations->where('evidence_band', 'On track')->count(),
            'evidence_behind' => $accreditations->where('evidence_band', 'Behind')->count(),
            'evidence_not_started' => $accreditations->where('evidence_band', 'Not started')->count(),
            'visits_within_60_days' => $accreditations->filter(
                fn (array $item) => is_int($item['days_until_visit']) && $item['days_until_visit'] >= 0 && $item['days_until_visit'] <= 60
            )->count(),
            'validity_expiring_90_days' => $accreditations->filter(
                fn (array $item) => is_int($item['days_until_validity']) && $item['days_until_validity'] >= 0 && $item['days_until_validity'] <= 90
            )->count(),
            'critical_programs' => $accreditations->where('risk_level', 'CRITICAL')->count(),
            'high_programs' => $accreditations->where('risk_level', 'HIGH')->count(),
            'watch_programs' => $accreditations->where('risk_level', 'WATCH')->count(),
        ];

        $notifications = $user->notifications()->orderByDesc('created_at')->limit(10)->get()->map(function ($notification) {
            $data = $notification->data ?? [];

            return [
                'id' => $notification->id,
                'title' => $data['title'] ?? 'Institutional update',
                'message' => $data['message'] ?? $data['title'] ?? 'Accreditation update',
                'type' => $data['type'] ?? 'info',
                'is_read' => (bool) $notification->read_at,
                'created_at' => $notification->created_at?->toDateTimeString(),
            ];
        })->values();

        $activity = $this->activity();

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'accreditations' => $accreditations,
            'upcoming' => $upcoming->map(fn (array $item) => [
                'id' => $item['id'],
                'program' => $item['program'],
                'program_id' => $item['program_id'],
                'college' => $item['college'],
                'level' => $item['level'],
                'phase' => $item['phase'],
                'preparation_status' => $item['preparation_status'],
                'validity_status' => $item['validity_status'],
                'accreditation_date' => $item['scheduled_visit'],
                'scheduled_visit' => $item['scheduled_visit'],
                'valid_until' => $item['valid_until'],
                'status' => $item['status'],
                'days_until_visit' => $item['days_until_visit'],
                'evidence_completion' => $item['evidence_completion'],
            ])->values(),
            'at_risk' => $atRisk->values(),
            'colleges' => $this->collegeRollup($accreditations),
            'alerts' => $this->alerts($accreditations),
            'readiness' => [
                'overall' => $overall,
                'programs' => $accreditations->map(fn (array $item) => [
                    'id' => $item['id'],
                    'program' => $item['program'],
                    'program_id' => $item['program_id'],
                    'college' => $item['college'],
                    'level' => $item['level'],
                    'readiness' => $item['readiness'],
                    'evidence_completion' => $item['evidence_completion'],
                    'evidence_band' => $item['evidence_band'],
                    'phase' => $item['phase'],
                    'preparation_status' => $item['preparation_status'],
                    'validity_status' => $item['validity_status'],
                    'valid_until' => $item['valid_until'],
                    'scheduled_visit' => $item['scheduled_visit'],
                    'weakest_areas' => $item['weakest_areas'],
                    'next_step' => $item['next_step'],
                ])->values(),
            ],
            'notifications' => $notifications,
            'recent_activity' => $activity,
        ];
    }

    /**
     * @return array{message: string, dean_name: string, dean_count: int}
     */
    public function notifyCollege(AccreditationCycle $cycle, User $sender, ?string $message): array
    {
        $cycle->loadMissing('program.college');
        $collegeId = $cycle->program?->college_id ?? $cycle->college_id;

        if (! $collegeId) {
            abort(422, 'This cycle is not attached to a college, so the follow-up cannot be delivered.');
        }

        $deans = User::query()
            ->where('college_id', $collegeId)
            ->whereHas('roles', fn ($query) => $query->where('name', RoleSlug::DEAN))
            ->get();

        if ($deans->isEmpty()) {
            abort(422, 'This college has no dean account, so the follow-up cannot be delivered.');
        }

        $serialized = $this->serializeCycle($cycle, $deans->groupBy('college_id'));
        $note = trim((string) $message);
        if ($note === '') {
            $reasons = collect($serialized['risk_reasons'])->take(3)->implode(' ');
            $note = trim($serialized['next_step'].($reasons !== '' ? ' '.$reasons : ''));
        }

        foreach ($deans as $dean) {
            $dean->notify(new VpaaCollegeFollowUpNotification(
                $cycle,
                $sender,
                $note,
                $serialized['risk_reasons'],
            ));
        }

        $names = $deans->pluck('name')->filter()->values();
        $label = $names->isEmpty() ? 'the college dean' : $names->implode(', ');

        return [
            'message' => 'Follow-up sent to '.$label.'.',
            'dean_name' => (string) ($names->first() ?? 'Dean'),
            'dean_count' => $deans->count(),
        ];
    }

    /**
     * @param  Collection<int|string, Collection<int, User>>  $deansByCollege
     * @return array<string, mixed>
     */
    private function serializeCycle(AccreditationCycle $cycle, Collection $deansByCollege): array
    {
        $program = $cycle->program;
        $college = $program?->college;
        $areas = $program ? $this->progress->breakdownForProgram($program) : [];
        $evidence = $areas === []
            ? (int) ($program?->compliance_score ?? 0)
            : (int) round((float) collect($areas)->avg('progressPercent'));
        $evidence = (int) min(100, max(0, $evidence));

        $daysUntilVisit = $cycle->scheduled_visit
            ? (int) now()->startOfDay()->diffInDays($cycle->scheduled_visit, false)
            : null;
        $daysUntilValidity = $cycle->valid_until
            ? (int) now()->startOfDay()->diffInDays($cycle->valid_until, false)
            : null;

        $assessment = $this->assess($cycle, $program, $areas, $evidence, $daysUntilVisit, $daysUntilValidity);
        $weakest = collect($areas)
            ->sortBy('progressPercent')
            ->take(3)
            ->map(fn (array $area) => [
                'label' => $area['label'],
                'name' => $area['name'],
                'progress' => (int) $area['progressPercent'],
            ])
            ->values()
            ->all();

        $deanGroup = $college
            ? ($deansByCollege->get($college->id) ?? $deansByCollege->get((string) $college->id))
            : null;
        $dean = $deanGroup?->first();
        $completeAreas = collect($areas)->filter(fn (array $area) => (int) $area['progressPercent'] >= 100)->count();

        return [
            'id' => $cycle->id,
            'program' => $program?->name ?? 'Unknown Program',
            'college' => $college?->name ?? 'Unknown College',
            'level' => $cycle->level,
            'active_cycle_id' => $program?->active_cycle_id,
            'is_active_level' => $program !== null && (int) $program->active_cycle_id === (int) $cycle->id,
            'phase' => $cycle->phase ?? 'Initial Notice',
            'status' => $cycle->status,
            'display_status' => $cycle->display_status,
            'workflow_status' => $cycle->workflow_status,
            'preparation_status' => $cycle->preparation_status,
            'validity_status' => $cycle->validity_status,
            'scheduled_visit' => $cycle->scheduled_visit?->toDateString(),
            'valid_until' => $cycle->valid_until?->toDateString(),
            'accreditation_date' => $cycle->scheduled_visit?->toDateString(),
            'deadline' => $cycle->valid_until?->toDateString(),
            'readiness' => $evidence,
            'readiness_label' => $cycle->readiness,
            'evidence_completion' => $evidence,
            'evidence_band' => $assessment['evidence_band'],
            'areas_total' => count($areas),
            'areas_complete' => $completeAreas,
            'weakest_areas' => $weakest,
            'days_until_visit' => $daysUntilVisit,
            'days_until_validity' => $daysUntilValidity,
            'at_risk' => in_array($assessment['risk_level'], ['CRITICAL', 'HIGH'], true),
            'risk_level' => $assessment['risk_level'],
            'risk' => $assessment['risk_reasons'][0] ?? null,
            'risk_reasons' => $assessment['risk_reasons'],
            'next_step' => $assessment['next_step'],
            'program_chair' => $program?->chairUser?->name,
            'has_program_chair' => (bool) $program?->chair_id,
            'dean_name' => $dean?->name,
            'has_dean' => $dean !== null,
            'program_id' => $program?->id,
            'college_id' => $college?->id,
            'created_at' => $cycle->created_at?->toDateTimeString(),
            'updated_at' => $cycle->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array{risk_level: string, risk_reasons: list<string>, evidence_band: string, next_step: string}
     */
    private function assess(
        AccreditationCycle $cycle,
        ?Program $program,
        array $areas,
        int $evidence,
        ?int $daysUntilVisit,
        ?int $daysUntilValidity,
    ): array {
        $reasons = [];
        $level = 'CLEAR';
        $rank = ['CLEAR' => 0, 'WATCH' => 1, 'HIGH' => 2, 'CRITICAL' => 3];
        $raise = function (string $next, string $reason) use (&$reasons, &$level, $rank): void {
            $reasons[] = $reason;
            if ($rank[$next] > $rank[$level]) {
                $level = $next;
            }
        };

        $visitPassed = $daysUntilVisit !== null && $daysUntilVisit < 0;
        $terminal = in_array($cycle->status, ['Ready', 'Completed'], true);
        $inPreparation = in_array($cycle->status, ['Preparation', 'Internal Review'], true);

        if ($cycle->isValidityExpired()) {
            $raise('CRITICAL', 'Accreditation validity has expired. The program cannot claim a current accredited status until the date is renewed.');
        } elseif ($daysUntilValidity !== null && $daysUntilValidity <= 90) {
            $raise('WATCH', "Validity expires in {$daysUntilValidity} days. Confirm the renewal visit is on the institutional schedule.");
            if ($daysUntilValidity <= 60 && $evidence < 70) {
                $raise('HIGH', "Validity expires in {$daysUntilValidity} days and evidence is only {$evidence}% complete.");
            }
        }

        if ($visitPassed && ! $terminal) {
            $raise('CRITICAL', 'The scheduled visit date has passed and the program is not marked ready.');
        } elseif ($daysUntilVisit !== null && $daysUntilVisit >= 0 && $daysUntilVisit <= 30 && $evidence < 70 && ! $terminal) {
            $raise('CRITICAL', "The survey visit is in {$daysUntilVisit} days and evidence is {$evidence}%. The exhibit will be incomplete.");
        } elseif ($daysUntilVisit !== null && $daysUntilVisit >= 0 && $daysUntilVisit <= 60 && $evidence < 80 && ! $terminal) {
            $raise('HIGH', "The survey visit is in {$daysUntilVisit} days and evidence is {$evidence}%. Close the remaining area gaps before the visit.");
        }

        if ($inPreparation && $evidence < 40) {
            $raise('HIGH', "Evidence completion is {$evidence}% while the cycle is already in {$cycle->status}.");
        }

        if ($inPreparation && ! $cycle->scheduled_visit) {
            $raise('WATCH', 'Preparation has started, but no accreditation visit date is on the schedule.');
        }

        if ($program && ! $program->chair_id && ! in_array($cycle->status, ['Planning', 'Completed'], true)) {
            $raise('WATCH', 'No program chair is assigned, so area work and evidence review have no owner.');
        }

        $emptyAreas = collect($areas)->filter(fn (array $area) => (int) $area['progressPercent'] === 0);
        if ($emptyAreas->isNotEmpty() && ! in_array($cycle->status, ['Planning', 'Completed'], true)) {
            $names = $emptyAreas->take(3)->pluck('label')->implode(', ');
            $extra = $emptyAreas->count() > 3 ? ' and '.($emptyAreas->count() - 3).' more' : '';
            $raise('WATCH', "These areas have no completed evidence rows: {$names}{$extra}.");
        }

        $band = match (true) {
            $cycle->status === 'Planning' && $evidence === 0 => 'Not started',
            $evidence >= 80 => 'Ready',
            $evidence >= 50 => 'On track',
            default => 'Behind',
        };

        $weakest = collect($areas)->sortBy('progressPercent')->first();

        $nextStep = 'No institutional blocker. Keep monitoring area completion through the visit.';
        if ($cycle->isValidityExpired()) {
            $nextStep = 'Record the renewed validity date, or open the next accreditation level with the college.';
        } elseif ($visitPassed && ! $terminal) {
            $nextStep = 'Confirm whether the visit happened and update the cycle status, or reschedule with the accreditor.';
        } elseif ($daysUntilVisit !== null && $daysUntilVisit >= 0 && $daysUntilVisit <= 60 && $evidence < 80 && ! $terminal) {
            $focus = $weakest ? ' Start with '.$weakest['label'].' ('.$weakest['progressPercent'].'%).' : '';
            $nextStep = 'Ask the dean to close evidence gaps before the survey.'.$focus;
        } elseif ($program && ! $program->chair_id && ! in_array($cycle->status, ['Planning', 'Completed'], true)) {
            $nextStep = 'Assign a program chair so area assignments and evidence review have an owner.';
        } elseif ($inPreparation && ! $cycle->scheduled_visit) {
            $nextStep = 'Set the survey visit date so the college can set evidence deadlines.';
        } elseif ($evidence < 50 && $cycle->status !== 'Planning') {
            $nextStep = $weakest
                ? 'Evidence is behind. '.$weakest['label'].' is the weakest area at '.$weakest['progressPercent'].'%.'
                : 'Evidence is behind the level expected for a program already in preparation.';
        } elseif ($daysUntilValidity !== null && $daysUntilValidity >= 0 && $daysUntilValidity <= 90) {
            $nextStep = 'Validity is inside the 90-day renewal window. Confirm the next visit is scheduled.';
        }

        return [
            'risk_level' => $level,
            'risk_reasons' => $reasons,
            'evidence_band' => $band,
            'next_step' => $nextStep,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accreditations
     * @return list<array<string, mixed>>
     */
    private function collegeRollup(Collection $accreditations): array
    {
        return $accreditations
            ->groupBy(fn (array $item) => (string) ($item['college_id'] ?? $item['college']))
            ->map(function (Collection $group) {
                $first = $group->first();
                $scores = $group->pluck('evidence_completion');

                return [
                    'id' => $first['college_id'],
                    'name' => $first['college'],
                    'programs' => $group->count(),
                    'average_readiness' => $scores->isEmpty() ? 0 : (int) round((float) $scores->avg()),
                    'at_risk' => $group->where('at_risk', true)->count(),
                    'critical' => $group->where('risk_level', 'CRITICAL')->count(),
                    'expired' => $group->where('validity_status', 'Expired')->count(),
                    'upcoming_visits' => $group->filter(
                        fn (array $item) => is_int($item['days_until_visit']) && $item['days_until_visit'] >= 0 && $item['days_until_visit'] <= 60
                    )->count(),
                    'behind' => $group->where('evidence_band', 'Behind')->count(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Operational alerts derived from live cycle data. These are what the
     * VPAA needs to act on, separate from the personal notification inbox.
     *
     * @param  Collection<int, array<string, mixed>>  $accreditations
     * @return list<array<string, mixed>>
     */
    private function alerts(Collection $accreditations): array
    {
        $order = ['CRITICAL' => 0, 'HIGH' => 1, 'WATCH' => 2];

        return $accreditations
            ->filter(fn (array $item) => $item['risk_level'] !== 'CLEAR')
            ->sortBy(function (array $item) use ($order) {
                $visit = is_int($item['days_until_visit']) ? $item['days_until_visit'] : 9999;

                return sprintf('%d-%05d', $order[$item['risk_level']] ?? 9, $visit + 1000);
            })
            ->map(fn (array $item) => [
                'id' => 'cycle-'.$item['id'].'-'.strtolower((string) $item['risk_level']),
                'cycle_id' => $item['id'],
                'severity' => $item['risk_level'],
                'title' => $item['program'].' · '.($item['level'] ?: 'Level not set'),
                'college' => $item['college'],
                'message' => $item['risk'] ?? 'Requires VPAA attention',
                'action' => $item['next_step'],
                'evidence_completion' => $item['evidence_completion'],
                'scheduled_visit' => $item['scheduled_visit'],
                'valid_until' => $item['valid_until'],
            ])
            ->values()
            ->all();
    }

    /**
     * Accreditation work recorded in the audit log, written as actions a
     * VPAA can read. Routine page views and sign-ins are left out.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(int $limit = 60): array
    {
        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->where('method', '!=', 'GET')
            ->where('status', 'success')
            ->whereNotIn('event', ['login', 'logout', '2fa_success', 'modify'])
            ->orderByDesc('id')
            ->limit(250)
            ->get();

        $cycleIds = [];
        $programIds = [];
        foreach ($logs as $log) {
            $path = (string) $log->path;
            if (preg_match('#accreditation-cycles/(\d+)#', $path, $matches)) {
                $cycleIds[] = (int) $matches[1];
            }
            if (preg_match('#(?:^|/)programs/(\d+)#', $path, $matches)) {
                $programIds[] = (int) $matches[1];
            }
        }

        $cycles = AccreditationCycle::with('program:id,name')
            ->whereIn('id', array_values(array_unique($cycleIds)))
            ->get()
            ->keyBy('id');
        $programs = Program::query()
            ->whereIn('id', array_values(array_unique($programIds)))
            ->get(['id', 'name'])
            ->keyBy('id');

        $items = [];
        foreach ($logs as $log) {
            $item = $this->describeLog($log, $cycles, $programs);
            if (! $item) {
                continue;
            }
            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  Collection<int, AccreditationCycle>  $cycles
     * @param  Collection<int, Program>  $programs
     * @return array<string, mixed>|null
     */
    private function describeLog(AuditLog $log, Collection $cycles, Collection $programs): ?array
    {
        $path = strtolower((string) $log->path);
        $event = (string) $log->event;
        $relevant = str_contains($path, 'accreditation')
            || str_contains($path, 'document')
            || str_contains($path, 'review')
            || str_contains($path, 'parameter')
            || str_contains($path, 'task-notification')
            || str_contains($path, 'board-of-action')
            || str_contains($path, 'instrument')
            || str_contains($path, 'program')
            || str_contains($path, 'area');

        if (! $relevant) {
            return null;
        }

        [$action, $type] = match (true) {
            str_contains($path, 'acknowledge') => ['acknowledged an accreditation notice', 'Acknowledged'],
            str_contains($path, 'forward-to-chair') => ['forwarded a cycle to the program chair', 'Forwarded'],
            str_contains($path, 'set-schedule') => ['updated a visit or validity date', 'Schedule'],
            str_contains($path, 'set-requirements') => ['set accreditation requirements', 'Updated'],
            str_contains($path, 'vpaa-monitor') => ['recorded VPAA monitoring', 'Monitoring'],
            str_contains($path, 'notify-college') => ['sent a follow-up to a college', 'Follow-up'],
            str_contains($path, 'accreditor') && str_contains($path, 'submit') => ['released evidence to the accreditor', 'Submitted'],
            $event === 'document_uploaded' || $event === 'upload' => ['uploaded accreditation evidence', 'Evidence'],
            $event === 'document_deleted' => ['removed an evidence file', 'Evidence'],
            $event === 'review_approved' => ['approved submitted evidence', 'Review'],
            $event === 'review_revision_requested' => ['requested a revision on evidence', 'Review'],
            $event === 'review_rejected' => ['returned evidence for correction', 'Review'],
            $event === 'review_submitted' => ['submitted evidence for review', 'Submitted'],
            $event === 'link_added' => ['attached an evidence link', 'Evidence'],
            str_contains($path, 'active-level') => ['changed the active accreditation level', 'Level'],
            str_contains($path, 'accreditation-cycles') && strtoupper((string) $log->method) === 'POST' && preg_match('#accreditation-cycles/\d+#', $path) !== 1 => ['opened an accreditation cycle', 'Created'],
            str_contains($path, 'accreditation-cycles') && in_array(strtoupper((string) $log->method), ['PUT', 'PATCH'], true) => ['updated an accreditation cycle', 'Updated'],
            str_contains($path, 'instrument') && in_array(strtoupper((string) $log->method), ['POST', 'PUT', 'PATCH'], true) => ['updated the survey instrument', 'Instrument'],
            str_contains($path, 'parameter') && in_array(strtoupper((string) $log->method), ['POST', 'PUT', 'PATCH'], true) => ['edited area parameter content', 'Instrument'],
            str_contains($path, 'task-notification') && strtoupper((string) $log->method) === 'POST' => ['assigned or updated a faculty task', 'Assignment'],
            str_contains($path, 'board-of-action') => ['updated a board of action file', 'Document'],
            str_contains($path, 'program') && strtoupper((string) $log->method) === 'POST' && preg_match('#programs/\d+#', $path) !== 1 => ['created a program', 'Created'],
            str_contains($path, 'program') && in_array(strtoupper((string) $log->method), ['PUT', 'PATCH'], true) => ['updated a program record', 'Updated'],
            str_contains($path, 'area') && in_array(strtoupper((string) $log->method), ['POST', 'PUT', 'PATCH'], true) => ['updated an accreditation area', 'Updated'],
            default => [null, null],
        };

        if (! is_string($action) || ! is_string($type)) {
            return null;
        }

        $actor = trim((string) ($log->user?->name ?: $log->user_email ?: 'System'));
        $details = null;
        if (preg_match('#accreditation-cycles/(\d+)#', $path, $matches)) {
            $cycle = $cycles->get((int) $matches[1]);
            if ($cycle) {
                $details = trim(($cycle->program?->name ?? 'Program').' · '.($cycle->level ?: 'Level not set'));
            }
        } elseif (preg_match('#programs/(\d+)#', $path, $matches)) {
            $details = $programs->get((int) $matches[1])?->name;
        }

        return [
            'id' => $log->id,
            'message' => trim($actor.' '.$action),
            'actor' => $actor !== '' ? $actor : 'System',
            'action' => $action,
            'details' => $details,
            'type' => $type,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
