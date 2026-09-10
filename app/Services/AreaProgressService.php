<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\College;
use App\Models\Document;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowStatus;
use App\Models\Program;
use App\Models\User;
use App\Support\ActiveCycle;
use Illuminate\Support\Collection;

class AreaProgressService
{
    /**
     * A content row is complete only when it is marked Done, has at least one
     * uploaded file, AND every file on the row is Approved by the Program Chair.
     */
    public function refresh(?AccreditationArea $area): int
    {
        if (! $area) {
            return 0;
        }

        $counts = $this->countsForArea($area);
        $percent = $counts['total'] === 0
            ? 0
            : (int) round(($counts['completed'] / $counts['total']) * 100);

        return $this->persist($area, $percent);
    }

    public function refreshForContentRow(?int $contentRowId): int
    {
        if (! $contentRowId) {
            return 0;
        }

        $this->clearDoneWithoutFiles($contentRowId);

        $row = ParameterContentRow::with('parameter.area')->find($contentRowId);

        return $this->refresh($row?->parameter?->area);
    }

    public function clearDoneWithoutFiles(?int $contentRowId): void
    {
        if (! $contentRowId) {
            return;
        }

        if (Document::query()->where('content_row_id', $contentRowId)->exists()) {
            return;
        }

        ParameterRowStatus::query()
            ->where('content_row_id', $contentRowId)
            ->update([
                'is_done' => false,
                'done_by' => null,
                'done_at' => null,
            ]);
    }

    /**
     * @return array{total: int, completed: int, inProgress: int, pending: int, notStarted: int}
     */
    public function countsForArea(?AccreditationArea $area): array
    {
        $empty = [
            'total' => 0,
            'completed' => 0,
            'inProgress' => 0,
            'pending' => 0,
            'notStarted' => 0,
        ];

        if (! $area) {
            return $empty;
        }

        $rowIds = ParameterContentRow::query()
            ->whereHas('parameter', fn ($query) => $query->where('area_id', $area->id))
            ->get()
            ->reject(fn (ParameterContentRow $row) => $row->isSectionHeading())
            ->pluck('id');

        return $this->countsForRowIds($rowIds);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $rowIds
     * @return array{total: int, completed: int, inProgress: int, pending: int, notStarted: int}
     */
    private function countsForRowIds($rowIds): array
    {
        $empty = [
            'total' => 0,
            'completed' => 0,
            'inProgress' => 0,
            'pending' => 0,
            'notStarted' => 0,
        ];

        $rowIds = collect($rowIds)->filter()->values();
        $total = $rowIds->count();

        if ($total === 0) {
            return $empty;
        }

        $uploadedRowIds = Document::query()
            ->whereIn('content_row_id', $rowIds)
            ->whereNotNull('content_row_id')
            ->pluck('content_row_id')
            ->unique();

        $doneRowIds = ParameterRowStatus::query()
            ->whereIn('content_row_id', $rowIds)
            ->where('is_done', true)
            ->pluck('content_row_id')
            ->unique();

        $unapprovedRowIds = Document::query()
            ->whereIn('content_row_id', $rowIds)
            ->whereNotNull('content_row_id')
            ->where('status', '!=', 'Approved')
            ->pluck('content_row_id')
            ->unique();

        $approvedRowIds = $uploadedRowIds->diff($unapprovedRowIds);
        $doneAndUploaded = $doneRowIds->intersect($uploadedRowIds);

        $completed = $doneAndUploaded->intersect($approvedRowIds)->count();
        $pending = $doneAndUploaded->diff($approvedRowIds)->count();
        $inProgress = $doneRowIds->diff($uploadedRowIds)
            ->merge($uploadedRowIds->diff($doneRowIds))
            ->unique()
            ->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'inProgress' => $inProgress,
            'pending' => $pending,
            'notStarted' => max(0, $total - $completed - $inProgress - $pending),
        ];
    }

    /**
     * Aggregate Task 7 row stats. Pending is Done+Uploaded rows awaiting Program Chair approval.
     *
     * @param  Collection<int, AccreditationArea>|iterable<AccreditationArea>  $areas
     * @return array{
     *     total: int,
     *     completed: int,
     *     inProgress: int,
     *     notStarted: int,
     *     pending: int,
     *     pendingReviews: int,
     *     progressPercent: int
     * }
     */
    public function workloadForAreas($areas): array
    {
        $areas = collect($areas);
        $areaIds = $areas->pluck('id')->filter()->values();

        $rowIds = $areaIds->isEmpty()
            ? collect()
            : ParameterContentRow::query()
                ->whereHas('parameter', fn ($query) => $query->whereIn('area_id', $areaIds))
                ->get()
                ->reject(fn (ParameterContentRow $row) => $row->isSectionHeading())
                ->pluck('id');

        $totals = $this->countsForRowIds($rowIds);
        $progressPercent = $totals['total'] === 0
            ? 0
            : (int) round(($totals['completed'] / $totals['total']) * 100);

        return [
            ...$totals,
            'pendingReviews' => $totals['pending'],
            'progressPercent' => $progressPercent,
        ];
    }

    /**
     * @param  Collection<int, AccreditationArea>|iterable<AccreditationArea>  $areas
     * @return list<array{id: int, name: string, email: ?string, role: string, focus: string}>
     */
    public function teamMembersForAreas($areas): array
    {
        $priority = [
            'Program Chair' => 0,
            'Area Chair' => 1,
            'Area Member' => 2,
        ];

        $people = collect();

        foreach (collect($areas) as $area) {
            $areaLabel = $area->sidebarLabel();
            $programChair = $area->cycle?->program?->chairUser;
            if ($programChair) {
                $people->push($this->teamPerson($programChair, 'Program Chair', $areaLabel));
            }
            if ($area->chair) {
                $people->push($this->teamPerson($area->chair, 'Area Chair', $areaLabel));
            }
            foreach ($area->members as $member) {
                if ($member->user) {
                    $people->push($this->teamPerson($member->user, 'Area Member', $areaLabel));
                }
            }
        }

        return $people
            ->groupBy('id')
            ->map(function (Collection $group) use ($priority) {
                $primary = $group->sortBy(fn (array $person) => $priority[$person['role']] ?? 9)->first();
                $primary['focus'] = $group->pluck('focus')->unique()->filter()->values()->implode(', ');

                return $primary;
            })
            ->sortBy(fn (array $person) => $priority[$person['role']] ?? 9)
            ->values()
            ->all();
    }

    public function breakdownForProgram(Program $program): array
    {
        $areas = $this->activeAreasForProgram($program);

        return $areas->map(function (AccreditationArea $area) {
            return [
                'id' => $area->id,
                'code' => $area->code,
                'name' => $area->name,
                'label' => $area->sidebarLabel(),
                'progressPercent' => $this->storedOrRefresh($area),
            ];
        })->values()->all();
    }

    /**
     * Canonical program completion: average of AACCUP area progress on the active cycle.
     */
    public function programPercent(Program $program): int
    {
        $areas = $this->activeAreasForProgram($program);

        if ($areas->isEmpty()) {
            return (int) ($program->compliance_score ?? 0);
        }

        $average = $areas->avg(fn (AccreditationArea $area) => (int) ($area->progress_percent ?? 0));

        return (int) round((float) $average);
    }

    public function collegePercent(College $college): int
    {
        $programs = Program::query()->where('college_id', $college->id)->get();

        if ($programs->isEmpty()) {
            return 0;
        }

        return (int) round($programs->avg(fn (Program $program) => $this->programPercent($program)));
    }

    public function syncProgramCompliance(?Program $program): int
    {
        if (! $program) {
            return 0;
        }

        $percent = $this->programPercent($program);

        if ((int) $program->compliance_score !== $percent) {
            $program->forceFill(['compliance_score' => $percent])->save();
        }

        return $percent;
    }

    public function refreshAndSyncProgram(Program $program): int
    {
        foreach ($this->activeAreasForProgram($program) as $area) {
            $this->refresh($area);
        }

        return $this->syncProgramCompliance($program->fresh() ?? $program);
    }

    /**
     * @return Collection<int, AccreditationArea>
     */
    public function activeAreasForProgram(Program $program): Collection
    {
        $program->loadMissing(['activeCycle', 'accreditationCycles']);
        $cycle = ActiveCycle::forProgram($program);

        if (! $cycle) {
            return collect();
        }

        return AccreditationArea::query()
            ->where('cycle_id', $cycle->id)
            ->whereNotNull('code')
            ->orderBy('code')
            ->orderBy('id')
            ->get();
    }

    public function storedOrRefresh(AccreditationArea $area): int
    {
        if ($area->progress_percent !== null && $area->progress_computed_at) {
            return (int) $area->progress_percent;
        }

        return $this->refresh($area);
    }

    private function teamPerson(User $user, string $role, string $areaLabel): array
    {
        $name = trim((string) ($user->name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? ''))));

        return [
            'id' => (int) $user->id,
            'name' => $name !== '' ? $name : 'Faculty',
            'email' => $user->email,
            'role' => $role,
            'focus' => $areaLabel,
        ];
    }

    private function persist(AccreditationArea $area, int $percent): int
    {
        $area->forceFill([
            'progress_percent' => $percent,
            'progress_computed_at' => now(),
        ])->save();

        $area->loadMissing('cycle.program');
        if ($area->cycle?->program) {
            $this->syncProgramCompliance($area->cycle->program);
        }

        return $percent;
    }
}
