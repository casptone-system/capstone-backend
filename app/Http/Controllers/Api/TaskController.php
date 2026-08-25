<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskAssignmentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Support\RoleGate;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Display a paginated list of tasks.
     */
    public function index(Request $request)
    {
        $query = Task::with('area', 'creator', 'assignments.user');

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('assigned_to')) {
            $query->whereHas('assignments', function ($q) use ($request) {
                $q->where('user_id', $request->assigned_to);
            });
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        $tasks = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Tasks retrieved successfully.',
            'data' => TaskResource::collection($tasks),
        ], 200);
    }

    /**
     * Store a newly created task.
     */
    public function store(Request $request)
    {
        RoleGate::denyQaMutations($request->user());

        $validated = $request->validate([
            'area_id' => ['required', 'exists:accreditation_areas,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:' . implode(',', Task::PRIORITIES)],
            'status' => ['nullable', 'in:' . implode(',', Task::STATUSES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $validated['created_by'] = $request->user()->id;

        $task = Task::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully.',
            'data' => new TaskResource($task->load('area', 'creator', 'assignments.user')),
        ], 201);
    }

    /**
     * Display the specified task.
     */
    public function show(Task $task)
    {
        $task->load('area.cycle.program', 'creator', 'assignments.user');

        return response()->json([
            'success' => true,
            'message' => 'Task retrieved successfully.',
            'data' => new TaskResource($task),
        ], 200);
    }

    /**
     * Update the specified task.
     */
    public function update(Request $request, Task $task)
    {
        RoleGate::denyQaMutations($request->user());

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:' . implode(',', Task::PRIORITIES)],
            'status' => ['nullable', 'in:' . implode(',', Task::STATUSES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $task->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully.',
            'data' => new TaskResource($task->load('area', 'creator', 'assignments.user')),
        ], 200);
    }

    /**
     * Remove the specified task.
     */
    public function destroy(Request $request, Task $task)
    {
        RoleGate::denyQaMutations($request->user());

        $task->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task deleted successfully.',
        ], 200);
    }

    /**
     * Assign members to the task.
     */
    public function assignMembers(Request $request, Task $task)
    {
        RoleGate::denyQaMutations($request->user());

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'exists:users,id'],
        ]);

        $now = now();
        $assigned = [];
        $newlyAssignedUserIds = [];

        foreach ($validated['user_ids'] as $userId) {
            $assignment = $task->assignments()->firstOrCreate(
                ['user_id' => $userId],
                ['assigned_at' => $now]
            );
            $assignment->load('user');
            $assigned[] = $assignment;

            // Track newly assigned users for notification
            if ($assignment->wasRecentlyCreated) {
                $newlyAssignedUserIds[] = $userId;
            }
        }

        // Send Task Assigned notification to newly assigned users
        if (!empty($newlyAssignedUserIds)) {
            $task->load('creator');
            $assignedByName = $request->user()->name;
            $users = User::whereIn('id', $newlyAssignedUserIds)->get();

            foreach ($users as $user) {
                $user->notify(new TaskAssignedNotification($task, $assignedByName));
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Members assigned successfully.',
            'data' => [
                'task' => new TaskResource($task->load('area', 'creator', 'assignments.user')),
                'assignments' => TaskAssignmentResource::collection(collect($assigned)),
            ],
        ], 200);
    }

    /**
     * Remove an assignment from the task.
     */
    public function removeAssignment(Task $task, TaskAssignment $assignment)
    {
        if ($assignment->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assignment does not belong to this task.',
            ], 404);
        }

        $assignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Member unassigned successfully.',
        ], 200);
    }

    /**
     * Mark the task as completed.
     */
    public function markComplete(Request $request, Task $task)
    {
        RoleGate::denyQaMutations($request->user());

        $task->update(['status' => 'Completed']);

        return response()->json([
            'success' => true,
            'message' => 'Task marked as completed.',
            'data' => new TaskResource($task->load('area', 'creator', 'assignments.user')),
        ], 200);
    }

    /**
     * Get the progress of a task.
     */
    public function progress(Task $task)
    {
        $task->load('area', 'creator', 'assignments.user');

        $totalAssignments = $task->assignments->count();
        $status = $task->status;

        $isOverdue = $task->due_date && $task->due_date->isPast() && $status !== 'Completed';

        return response()->json([
            'success' => true,
            'message' => 'Task progress retrieved successfully.',
            'data' => [
                'task' => new TaskResource($task),
                'progress' => [
                    'status' => $status,
                    'totalAssignments' => $totalAssignments,
                    'isOverdue' => $isOverdue,
                    'hasDueDate' => $task->due_date !== null,
                    'dueDate' => $task->due_date?->toDateString(),
                ],
            ],
        ], 200);
    }
}