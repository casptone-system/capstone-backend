<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SystemController extends Controller
{
    protected function ensureSuperAdmin(Request $request): ?User
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user || ! (
            $user->hasRole('Super Administrator') ||
            $user->hasRole('Super Admin') ||
            $user->hasRole('super administrator')
        )) {
            return null;
        }

        return $user;
    }

    public function settings(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view system settings.'], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'System settings retrieved successfully.',
            'data' => [
                'backup_enabled' => true,
                'email_configured' => true,
                'notifications_enabled' => true,
                'storage_limit_mb' => 5000,
                'retention_days' => 90,
            ],
        ]);
    }

    public function backup(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to run backups.'], 403);
        }

        $exitCode = Artisan::call('database:backup', ['--path' => 'admin']);

        if ($exitCode !== 0) {
            return response()->json(['success' => false, 'message' => 'Backup process failed.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Database backup completed successfully.',
        ]);
    }
}
