<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\ParameterContentRow;
use App\Models\User;

class AreaEvidenceGate
{
    public static function resolveArea(?int $areaId, ?int $contentRowId): ?AccreditationArea
    {
        if ($contentRowId) {
            $row = ParameterContentRow::with('parameter.area')->find($contentRowId);

            return $row?->parameter?->area;
        }

        if ($areaId) {
            return AccreditationArea::find($areaId);
        }

        return null;
    }

    public static function assertCanUpload(?User $user, ?AccreditationArea $area): void
    {
        if (! $user || ! $area || ! $user->isChairOfArea($area)) {
            abort(403, 'Only the assigned Area Chair may upload files for this area.');
        }
    }
}
