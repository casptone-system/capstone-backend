<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function view(User $user): bool
    {
        // Superadmin or users with explicit permission can view audit logs
        if ($user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo('view audit logs');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function viewLoginHistory(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo('view login history');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
