<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Display a paginated list of the authenticated user's notifications.
     */
    public function index(Request $request)
    {
        $query = $request->user()->notifications();

        // Filter by read/unread
        if ($request->filled('filter')) {
            if ($request->filter === 'unread') {
                $query->unread();
            } elseif ($request->filter === 'read') {
                $query->read();
            }
        }

        // Filter by notification type
        if ($request->filled('type')) {
            $query->where('data->type', $request->type);
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 100);
        $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully.',
            'data' => NotificationResource::collection($notifications->items())->resolve(),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ], 200);
    }

    /**
     * Display the specified notification (must belong to the user).
     */
    public function show(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification retrieved successfully.',
            'data' => new NotificationResource($notification),
        ], 200);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => new NotificationResource($notification->fresh()),
        ], 200);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ], 200);
    }

    /**
     * Get the count of unread notifications.
     */
    public function unreadCount(Request $request)
    {
        $count = $request->user()->unreadNotifications()->count();

        return response()->json([
            'success' => true,
            'message' => 'Unread notification count retrieved successfully.',
            'data' => [
                'unreadCount' => $count,
            ],
        ], 200);
    }

    /**
     * Download an instrument file attached to a notification.
     */
    public function downloadInstrumentFile(Request $request, string $notificationId)
    {
        $notification = $request->user()->notifications()->where('id', $notificationId)->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $filePath = $notification->data['instrument_file_path'] ?? null;
        $fileName = $notification->data['instrument_file_name'] ?? null;

        if (!$filePath || !$fileName) {
            return response()->json([
                'success' => false,
                'message' => 'No instrument file attached to this notification.',
            ], 400);
        }

        $fullPath = storage_path('app/private/' . $filePath);

        if (!file_exists($fullPath)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found on server.',
            ], 404);
        }

        // Mark the notification as read when downloaded
        $notification->markAsRead();

        return response()->download($fullPath, $fileName);
    }

    /**
     * Remove the specified notification.
     */
    public function destroy(Request $request, string $id)
    {
        $id = preg_replace('/^(inbox|task):/i', '', trim($id)) ?: $id;
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $notification
                ? 'Notification deleted successfully.'
                : 'Notification already dismissed.',
        ], 200);
    }
}