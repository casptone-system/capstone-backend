<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\Document;
use App\Models\ParameterContentRow;

class AreaProgressService
{
    public function refresh(?AccreditationArea $area): int
    {
        if (! $area) {
            return 0;
        }

        $rowIds = ParameterContentRow::query()
            ->whereHas('parameter', fn ($query) => $query->where('area_id', $area->id))
            ->get()
            ->reject(fn (ParameterContentRow $row) => $row->isSectionHeading())
            ->pluck('id');

        $total = $rowIds->count();

        if ($total === 0) {
            return $this->persist($area, 0);
        }

        $uploadedRowIds = Document::query()
            ->whereIn('content_row_id', $rowIds)
            ->whereNotNull('content_row_id')
            ->pluck('content_row_id')
            ->unique();

        $percent = (int) round(($uploadedRowIds->count() / $total) * 100);

        return $this->persist($area, $percent);
    }

    public function refreshForContentRow(?int $contentRowId): int
    {
        if (! $contentRowId) {
            return 0;
        }

        $row = ParameterContentRow::with('parameter.area')->find($contentRowId);

        return $this->refresh($row?->parameter?->area);
    }

    public function breakdownForProgram(\App\Models\Program $program): array
    {
        $program->loadMissing(['activeCycle', 'accreditationCycles']);
        $cycle = \App\Support\ActiveCycle::forProgram($program);

        if (! $cycle) {
            return [];
        }

        $areas = AccreditationArea::query()
            ->where('cycle_id', $cycle->id)
            ->whereNotNull('code')
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        return $areas->map(function (AccreditationArea $area) {
            $percent = $area->progress_computed_at === null
                ? $this->refresh($area)
                : (int) ($area->progress_percent ?? 0);

            return [
                'id' => $area->id,
                'code' => $area->code,
                'name' => $area->name,
                'label' => $area->sidebarLabel(),
                'progressPercent' => $percent,
            ];
        })->values()->all();
    }

    private function persist(AccreditationArea $area, int $percent): int
    {
        $area->forceFill([
            'progress_percent' => $percent,
            'progress_computed_at' => now(),
        ])->save();

        return $percent;
    }
}
