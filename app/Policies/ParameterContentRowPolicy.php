<?php

namespace App\Policies;

use App\Models\AccreditationArea;
use App\Models\ParameterContentRow;
use App\Models\User;
use App\Support\OrgScope;

class ParameterContentRowPolicy
{
    public function viewComments(User $user, ParameterContentRow $row): bool
    {
        return $this->canAccess($user, $row);
    }

    public function comment(User $user, ParameterContentRow $row): bool
    {
        return $this->canAccess($user, $row);
    }

    private function canAccess(User $user, ParameterContentRow $row): bool
    {
        $row->loadMissing('parameter.area.cycle');

        return self::canAccessArea($user, $row->parameter?->area);
    }

    /**
     * Shared by the policy above and by controllers that need the same
     * "assigned to the area OR reviewer with visibility into its program"
     * rule without loading a full ParameterContentRow instance.
     */
    public static function canAccessArea(?User $user, ?AccreditationArea $area): bool
    {
        if (! $user || ! $area) {
            return false;
        }

        if ($user->isAssignedToArea($area)) {
            return true;
        }

        $programId = $area->cycle?->program_id;

        return $programId !== null && OrgScope::canSeeProgram($user, (int) $programId);
    }
}
