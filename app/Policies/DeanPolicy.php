<?php

namespace App\Policies;

use App\Models\College;
use App\Models\User;

class DeanPolicy
{
    public function accessCollegeDashboard(User $user): bool
    {
        if (! $user->isDean()) {
            return false;
        }

        return (bool) $user->college_id;
    }

    public function monitorCollege(User $user, ?College $college = null): bool
    {
        if (! $user->isDean()) {
            return false;
        }

        $effectiveCollegeId = $user->college_id;

        if (! $effectiveCollegeId) {
            return false;
        }

        if (! $college) {
            return true;
        }

        return (int) $college->id === (int) $effectiveCollegeId;
    }

    public function reviewCollegeDocuments(User $user, ?College $college = null): bool
    {
        if (! $this->monitorCollege($user, $college)) {
            return false;
        }

        return true;
    }
}
