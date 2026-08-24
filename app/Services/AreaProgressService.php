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
            ->pluck('id');

        $total = $rowIds->count();

        if ($total === 0) {
            return $this->persist($area, 0);
        }

        $doneRowIds = ParameterContentRow::query()
            ->whereIn('id', $rowIds)
            ->whereHas('status', fn ($query) => $query->where('is_done', true))
            ->pluck('id');

        $uploadedRowIds = Document::query()
            ->whereIn('content_row_id', $rowIds)
            ->whereNotNull('content_row_id')
            ->pluck('content_row_id')
            ->unique();

        $complete = $doneRowIds->intersect($uploadedRowIds)->count();
        $percent = (int) round(($complete / $total) * 100);

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

    private function persist(AccreditationArea $area, int $percent): int
    {
        $area->forceFill([
            'progress_percent' => $percent,
            'progress_computed_at' => now(),
        ])->save();

        return $percent;
    }
}
