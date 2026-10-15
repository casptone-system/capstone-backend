<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Document;

final class AccreditorRelease
{
    public static function isReleased(?AccreditationCycle $cycle): bool
    {
        return $cycle !== null && $cycle->submitted_to_accreditor_at !== null;
    }

    public static function areaIsVisible(?AccreditationArea $area): bool
    {
        if (! $area) {
            return false;
        }

        $area->loadMissing('cycle');

        return self::isReleased($area->cycle);
    }

    public static function documentIsVisible(Document $document): bool
    {
        $document->loadMissing(['area.cycle', 'contentRow.parameter.area.cycle']);
        $area = $document->area ?: $document->contentRow?->parameter?->area;

        return self::areaIsVisible($area);
    }
}
