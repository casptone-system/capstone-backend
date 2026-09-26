<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\User;

final class RowCommentRoleLabel
{
    /**
     * Human-readable label snapshotted onto a comment at write time so the
     * thread keeps showing "Program Chair" / "Area Chair" / etc. even if the
     * author's role changes later.
     */
    public static function for(User $user, ?AccreditationArea $area): string
    {
        if ($area && $user->isChairOfArea($area)) {
            return 'Area Chair';
        }

        if ($area && $user->isAssignedToArea($area)) {
            return 'Member';
        }

        if ($user->isSuperAdmin()) {
            return 'Super Admin';
        }

        if ($user->isDean()) {
            return 'Dean';
        }

        if ($user->isProgramChair()) {
            return 'Program Chair';
        }

        if ($user->isAccreditor()) {
            return 'Accreditor';
        }

        if ($user->isQA()) {
            return 'QA';
        }

        if ($user->isVPAA()) {
            return 'VPAA/DI';
        }

        return 'User';
    }
}
