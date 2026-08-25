<?php

namespace App\Support;

use App\Models\User;

final class RoleGate
{
    public static function denyQaMutations(?User $user): void
    {
        if ($user?->isQA()) {
            abort(403, 'QA may monitor all departments but cannot change accreditation records.');
        }
    }

    public static function assertCanSetAccreditationSchedule(?User $user): void
    {
        if (! $user || (! $user->isVPAA() && ! $user->isSuperAdmin())) {
            abort(403, 'Only the VPAA/DI can set the accreditation schedule and validity.');
        }
    }
}
