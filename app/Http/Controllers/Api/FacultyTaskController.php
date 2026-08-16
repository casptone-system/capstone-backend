<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\AccreditationRequirement;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacultyTaskController extends Controller
{
    /**
     * Assign a faculty member to an accreditation requirement.
     * POST /api/accreditation-requirements/{requirement}/assign-faculty
     */
    public function assignFacultyToRequirement(Request $request, AccreditationRequirement $requirement)
    {
        $user = $request->user();

        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only the Program Chair can assign faculty to requirements.');
        }

        // Load the area and cycle for validation
        $area = $requirement->area()->with('cycle.program')->firstOrFail();
        $cycle = $area->cycle;

        // Verify Program Chair owns this program
        if ((int) $cycle->program_id !== (int) $user->getEffectiveProgramId()) {
            abort(403, 'You are not the Program Chair for this program.');
        }

        $validated = $request->validate([
            'faculty_id' => ['required', 'exists:users,id'],
            'deadline' => ['nullable', 'date', 'after:today'],
            'instructions' => ['nullable', 'string'],
        ]);

        // Verify faculty belongs to this program and has Faculty role
        $faculty = User::findOrFail($validated['faculty_id']);

        if (! $faculty->isFaculty()) {
            abort(422, 'The selected user must have the Faculty role.');
        }

        if (! $cycle->program->members()->where('user_id', $faculty->id)->exists()) {
            abort(422, 'The selected faculty member does not belong to this program.');
        }

        // Create task through transaction
        $task = DB::transaction(function () use ($requirement, $area, $cycle, $user, $faculty, $validated) {
            // Create task
            $task = Task::create([
                'accreditation_cycle_id' => $cycle->id,
                'program_id' => $cycle->program_id,
                'area_id' => $area->id,
                'requirement_id' => $requirement->id,
                'assigned_by' => $user->id,
                'title' => $requirement->title,
                'description' => $requirement->description,
                'instructions' => $validated['instructions'] ?? $requirement->evidence_guidance,
                'status' => 'Not Started',
                'deadline' => $validated['deadline'] ?? null,
                'priority' => 'High',
                'created_by' => $user->id,
            ]);

            // Assign faculty to task
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $faculty->id,
                'assigned_at' => now(),
            ]);

            return $task;
        });

        // Send notification
        $faculty->notify(new TaskAssignedNotification($task, $user->name));

        return response()->json([
            'success' => true,
            'message' => 'Faculty assigned to requirement successfully.',
            'data' => new TaskResource(
                $task->load('area', 'cycle', 'program', 'requirement', 'creator', 'assignedBy', 'assignments.user')
            ),
        ], 201);
    }

    /**
     * Get all tasks assigned to the current faculty member.
     * GET /api/faculty/tasks
     */
    public function getFacultyTasks(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isFaculty()) {
            abort(403, 'Only Faculty can view their tasks.');
        }

        $query = TaskAssignment::where('user_id', $user->id)
            ->with([
                'task.area',
                'task.cycle.program',
                'task.requirement',
                'task.assignedBy',
                'task.creator',
            ])
            ->orderBy('assigned_at', 'desc');

        if ($request->filled('status')) {
            $query->whereHas('task', fn ($q) => $q->where('status', $request->status));
        }

        $assignments = $query->paginate($request->get('per_page', 15));

        // Transform to return task data
        $tasks = collect($assignments->items())->map(fn ($assignment) => $assignment->task);

        return response()->json([
            'success' => true,
            'data' => TaskResource::collection($tasks),
            'meta' => [
                'total' => $assignments->total(),
                'count' => count($tasks),
                'per_page' => $assignments->perPage(),
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
            ],
        ]);
    }

    /**
     * Get a specific faculty task.
     * GET /api/faculty/tasks/{task}
     */
    public function getFacultyTask(Request $request, Task $task)
    {
        $user = $request->user();

        if (! $user || ! $user->isFaculty()) {
            abort(403, 'Only Faculty can view their tasks.');
        }

        // Verify task belongs to this faculty
        if (! $task->assignments()->where('user_id', $user->id)->exists()) {
            abort(403, 'You are not assigned to this task.');
        }

        $task->load([
            'area',
            'cycle.program',
            'requirement',
            'assignedBy',
            'creator',
            'assignments.user',
        ]);

        return response()->json([
            'success' => true,
            'data' => new TaskResource($task),
        ]);
    }

    /**
     * Update a faculty task (e.g., status change).
     * PATCH /api/faculty/tasks/{task}
     */
    public function updateFacultyTask(Request $request, Task $task)
    {
        $user = $request->user();

        if (! $user || ! $user->isFaculty()) {
            abort(403, 'Only Faculty can update their tasks.');
        }

        // Verify task belongs to this faculty
        if (! $task->assignments()->where('user_id', $user->id)->exists()) {
            abort(403, 'You are not assigned to this task.');
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'in:' . implode(',', Task::STATUSES)],
            'instructions' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:' . implode(',', Task::PRIORITIES)],
        ]);

        $task->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully.',
            'data' => new TaskResource(
                $task->load('area', 'cycle', 'requirement', 'assignedBy', 'assignments.user')
            ),
        ]);
    }

    /**
     * Submit a faculty task with evidence.
     * POST /api/faculty/tasks/{task}/submit
     */
    public function submitFacultyTask(Request $request, Task $task)
    {
        $user = $request->user();

        if (! $user || ! $user->isFaculty()) {
            abort(403, 'Only Faculty can submit tasks.');
        }

        // Verify task belongs to this faculty
        if (! $task->assignments()->where('user_id', $user->id)->exists()) {
            abort(403, 'You are not assigned to this task.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:Submitted,Resubmitted'],
            'submitted_notes' => ['nullable', 'string'],
        ]);

        $task->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Task submitted successfully.',
            'data' => new TaskResource(
                $task->load('area', 'cycle', 'requirement', 'assignedBy', 'assignments.user')
            ),
        ]);
    }

    /**
     * Get tasks pending review for the current Program Chair.
     * GET /api/program-chair/tasks-pending-review
     */
    public function getProgramChairPendingReview(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only Program Chair can view pending reviews.');
        }

        $programId = $user->getEffectiveProgramId();

        if (! $programId) {
            abort(403, 'You are not assigned to any program.');
        }

        $tasks = Task::where('program_id', $programId)
            ->whereIn('status', ['Submitted', 'Resubmitted'])
            ->with([
                'area',
                'cycle.program',
                'requirement',
                'assignments.user',
                'creator',
            ])
            ->orderBy('updated_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => TaskResource::collection($tasks),
            'meta' => [
                'total' => $tasks->total(),
                'count' => count($tasks->items()),
                'per_page' => $tasks->perPage(),
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
            ],
        ]);
    }

    /**
     * Approve a submitted faculty task.
     * POST /api/faculty/tasks/{task}/approve
     */
    public function approveFacultyTask(Request $request, Task $task)
    {
        $user = $request->user();

        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only Program Chair can approve tasks.');
        }

        // Verify task belongs to this program
        if ((int) $task->program_id !== (int) $user->getEffectiveProgramId()) {
            abort(403, 'You are not authorized to review this task.');
        }

        if (! in_array($task->status, ['Submitted', 'Resubmitted'])) {
            abort(422, 'Only submitted or resubmitted tasks can be approved.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:Approved'],
            'reviewer_notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($task, $validated) {
            // Update task status
            $task->update(['status' => $validated['status']]);

            // Update requirement status to Completed
            if ($task->requirement) {
                $task->requirement->update(['status' => 'Completed']);
            }

            // Notify faculty
            $faculty = $task->assignments()->first()?->user;
            if ($faculty) {
                $faculty->notify(
                    new \App\Notifications\TaskApprovedNotification($task, $validated['reviewer_notes'] ?? null)
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Task approved successfully.',
            'data' => new TaskResource(
                $task->fresh()->load('area', 'cycle', 'requirement', 'assignedBy', 'assignments.user')
            ),
        ]);
    }

    /**
     * Return a submitted task for revision.
     * POST /api/faculty/tasks/{task}/return
     */
    public function returnFacultyTask(Request $request, Task $task)
    {
        $user = $request->user();

        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only Program Chair can return tasks.');
        }

        // Verify task belongs to this program
        if ((int) $task->program_id !== (int) $user->getEffectiveProgramId()) {
            abort(403, 'You are not authorized to review this task.');
        }

        if (! in_array($task->status, ['Submitted', 'Resubmitted'])) {
            abort(422, 'Only submitted or resubmitted tasks can be returned.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:Returned'],
            'return_reason' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($task, $validated) {
            // Store return reason in a way that persists
            // For now, we'll add it as an attribute
            $task->update([
                'status' => $validated['status'],
            ]);

            // Store return reason (we'll handle this via model attribute or separate table in future)
            // For now, add it to description temporarily
            $task->return_reason = $validated['return_reason'];
            $task->save();

            // Notify faculty
            $faculty = $task->assignments()->first()?->user;
            if ($faculty) {
                $faculty->notify(
                    new \App\Notifications\TaskReturnedNotification($task, $validated['return_reason'])
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Task returned for revision.',
            'data' => new TaskResource(
                $task->fresh()->load('area', 'cycle', 'requirement', 'assignedBy', 'assignments.user')
            ),
        ]);
    }
}
