<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskNotificationController extends Controller
{
    /**
     * Get all active task notifications for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $tasks = TaskNotification::getActiveForUser($user)
            ->with([
                'assignedBy:id,first_name,last_name,email',
                'files:id,task_notification_id,file_name,file_path,mime_type,file_size,file_type,created_at'
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tasks,
            'badge_count' => TaskNotification::getActiveBadgeCount($user),
        ]);
    }

    /**
     * Get pending tasks only (not yet viewed)
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $tasks = TaskNotification::getPendingForUser($user)
            ->with([
                'assignedBy:id,first_name,last_name,email',
                'files:id,task_notification_id,file_name,file_path,mime_type,file_size,file_type,created_at'
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tasks,
            'count' => $tasks->count(),
        ]);
    }

    /**
     * Get badge count for current user
     */
    public function getBadgeCount(Request $request): JsonResponse
    {
        $badgeCount = TaskNotification::getActiveBadgeCount($request->user());

        return response()->json([
            'success' => true,
            'badge_count' => $badgeCount,
        ]);
    }

    /**
     * Assign a task to a program chair (Dean only)
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $canAssignDeanTasks = $currentUser->isDean();
        $canAssignChairTasks = $currentUser->isProgramChair();

        if (!$canAssignDeanTasks && !$canAssignChairTasks) {
            return response()->json([
                'success' => false,
                'message' => 'Only deans and program chairs can assign tasks',
            ], 403);
        }

        $validated = $request->validate([
            'assigned_to_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'in:document_upload,review,approval,assignment,other',
            'badge_clear_hours' => 'integer|min:1|max:720', // 1 hour to 30 days
            'related_id' => 'nullable|integer',
            'related_model' => 'nullable|string',
        ]);

        $assignedUser = User::findOrFail($validated['assigned_to_id']);

        if ($canAssignDeanTasks && !$assignedUser->isProgramChair()) {
            return response()->json([
                'success' => false,
                'message' => 'Dean tasks can only be assigned to program chairs',
            ], 422);
        }

        if ($canAssignChairTasks && !$assignedUser->isFaculty()) {
            return response()->json([
                'success' => false,
                'message' => 'Program chair tasks can only be assigned to faculty members',
            ], 422);
        }

        // Create task notification
        $task = TaskNotification::create([
            'assigned_by_id' => $request->user()->id,
            'assigned_to_id' => $validated['assigned_to_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'] ?? 'assignment',
            'related_id' => $validated['related_id'] ?? null,
            'related_model' => $validated['related_model'] ?? null,
            'badge_clear_hours' => $validated['badge_clear_hours'] ?? 48,
            'status' => 'pending',
        ]);

        // Send notification to program chair (if you have a notification system)
        // $assignedUser->notify(new TaskAssigned($task));

        return response()->json([
            'success' => true,
            'message' => 'Task assigned successfully',
            'data' => $task->load('assignedBy'),
        ], 201);
    }

    /**
     * Mark a task as viewed (chair viewing the notification)
     */
    public function markAsViewed(Request $request, TaskNotification $task): JsonResponse
    {
        // Verify user is the assigned recipient
        if ($task->assigned_to_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $task->markAsViewed();

        return response()->json([
            'success' => true,
            'message' => 'Task marked as viewed',
            'data' => $task,
            'badge_count' => TaskNotification::getActiveBadgeCount($request->user()),
        ]);
    }

    /**
     * Mark a task as completed
     */
    public function markAsCompleted(Request $request, TaskNotification $task): JsonResponse
    {
        // Verify user is the assigned recipient
        if ($task->assigned_to_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $task->markAsCompleted();

        return response()->json([
            'success' => true,
            'message' => 'Task marked as completed',
            'data' => $task,
        ]);
    }

    /**
     * Delete/dismiss a task notification
     */
    public function dismiss(Request $request, TaskNotification $task): JsonResponse
    {
        // Verify user is the assigned recipient
        if ($task->assigned_to_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $task->update(['status' => 'dismissed']);

        return response()->json([
            'success' => true,
            'message' => 'Task dismissed',
            'badge_count' => TaskNotification::getActiveBadgeCount($request->user()),
        ]);
    }

    /**
     * Upload file to task notification
     */
    public function uploadFile(Request $request, TaskNotification $task): JsonResponse
    {
        // Verify user is the task assigner (dean)
        if ($task->assigned_by_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the task assigner can upload files',
            ], 403);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:51200', // 50MB
            'file_type' => 'in:instrument,document,reference,other',
            'description' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $folderPath = 'tasks/' . $task->id . '/files';
        $filePath = $file->store($folderPath, 'public');

        $taskFile = TaskNotificationFile::create([
            'task_notification_id' => $task->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_type' => $validated['file_type'] ?? 'instrument',
            'description' => $validated['description'] ?? null,
        ]);

        // Enable files if this is the first upload
        if (!$task->files_enabled) {
            $task->update([
                'files_enabled' => true,
                'file_folder_path' => $folderPath,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully',
            'data' => $taskFile,
        ]);
    }

    /**
     * Download file from task notification
     */
    public function downloadFile(Request $request, TaskNotification $task, TaskNotificationFile $file): JsonResponse
    {
        // Verify file belongs to task
        if ($file->task_notification_id !== $task->id) {
            return response()->json([
                'success' => false,
                'message' => 'File not found',
            ], 404);
        }

        // Verify user is recipient or assigner
        $user = $request->user();
        if ($task->assigned_to_id !== $user->id && $task->assigned_by_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Return download URL
        return response()->json([
            'success' => true,
            'download_url' => asset('storage/' . $file->file_path),
            'file_name' => $file->file_name,
        ]);
    }

    /**
     * Forward file to faculty member
     */
    public function forwardFile(Request $request, TaskNotification $task, TaskNotificationFile $file): JsonResponse
    {
        // Verify file belongs to task
        if ($file->task_notification_id !== $task->id) {
            return response()->json([
                'success' => false,
                'message' => 'File not found',
            ], 404);
        }

        // Verify user is recipient (program chair)
        if ($task->assigned_to_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validated = $request->validate([
            'to_user_id' => 'required|exists:users,id',
            'message' => 'nullable|string|max:1000',
        ]);

        // Verify recipient exists and is a faculty member
        $recipient = User::findOrFail($validated['to_user_id']);
        if (!$recipient->isFaculty()) {
            return response()->json([
                'success' => false,
                'message' => 'Files can only be forwarded to faculty members',
            ], 422);
        }

        // Create forward record
        $forward = TaskNotificationFileForward::create([
            'task_notification_id' => $task->id,
            'task_notification_file_id' => $file->id,
            'from_user_id' => $request->user()->id,
            'to_user_id' => $validated['to_user_id'],
            'message' => $validated['message'] ?? null,
        ]);

        // Create notification for recipient
        TaskNotification::create([
            'assigned_by_id' => $request->user()->id,
            'assigned_to_id' => $validated['to_user_id'],
            'title' => 'File forwarded: ' . $file->file_name,
            'description' => $validated['message'] ?? 'A file has been forwarded to you from ' . $request->user()->name,
            'type' => 'document_upload',
            'status' => 'pending',
            'badge_clear_hours' => 72,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File forwarded successfully',
            'data' => $forward,
        ]);
    }

    /**
     * Get all files for a task
     */
    public function getFiles(Request $request, TaskNotification $task): JsonResponse
    {
        // Verify user is recipient or assigner
        $user = $request->user();
        if ($task->assigned_to_id !== $user->id && $task->assigned_by_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $files = $task->files()
            ->with('forwards.toUser:id,name,email')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $files,
            'count' => $files->count(),
        ]);
    }
}
