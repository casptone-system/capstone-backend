<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\AuditLogSummaryResource;
use App\Http\Resources\LoginHistoryResource;
use App\Models\AuditLog;
use App\Models\AuditLogSummary;
use App\Models\LoginHistory;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->isSuperAdmin() && ! $user->hasPermissionTo('view audit logs'))) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view audit logs.'], 403);
        }

        $logs = AuditLog::with('details')
            ->where('event', '!=', 'modify')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('user_email'), fn ($q) => $q->where('user_email', 'like', "%{$request->user_email}%"))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('event', 'like', $term)
                        ->orWhere('user_email', 'like', $term)
                        ->orWhere('path', 'like', $term)
                        ->orWhere('status', 'like', $term);
                });
            })
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->event))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('path'), fn ($q) => $q->where('path', 'like', "%{$request->path}%"))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Audit logs retrieved successfully.',
            'data' => AuditLogResource::collection($logs)->resolve(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ], 200);
    }

    public function loginHistory(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->isSuperAdmin() && ! $user->hasPermissionTo('view login history'))) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view login history.'], 403);
        }

        $histories = LoginHistory::when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('email'), fn ($q) => $q->where('email', 'like', "%{$request->email}%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->to))
            ->orderBy('occurred_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Login history retrieved successfully.',
            'data' => LoginHistoryResource::collection($histories)->resolve(),
            'meta' => [
                'current_page' => $histories->currentPage(),
                'last_page' => $histories->lastPage(),
                'per_page' => $histories->perPage(),
                'total' => $histories->total(),
            ],
        ], 200);
    }

    public function summaries(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->isSuperAdmin() && ! $user->hasPermissionTo('view audit logs'))) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view audit summaries.'], 403);
        }

        $summaries = AuditLogSummary::when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->event))
            ->orderBy('total_count', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Audit summaries retrieved successfully.',
            'data' => AuditLogSummaryResource::collection($summaries),
            'meta' => [
                'pagination' => $summaries->toArray(),
            ],
        ], 200);
    }

    public function sessions(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->isSuperAdmin() && ! $user->hasPermissionTo('view login history'))) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view sessions.'], 403);
        }

        $sessions = \Illuminate\Support\Facades\DB::table('sessions')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->orderByDesc('last_activity')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Sessions retrieved successfully.',
            'data' => $sessions->items(),
            'meta' => ['pagination' => $sessions->toArray()],
        ], 200);
    }
}
