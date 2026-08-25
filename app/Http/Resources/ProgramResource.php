<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $facultyUsers = User::where('program_id', $this->id)
            ->whereHas('roles', fn ($query) => $query->where('name', \App\Support\RoleSlug::FACULTY))
            ->get();

        $faculty = $facultyUsers->map(function (User $user): array {
            return [
                'id' => $user->id,
                'name' => trim(sprintf('%s %s %s', $user->first_name, $user->middle_name ?? '', $user->last_name)),
                'email' => $user->email,
                'profilePhoto' => $user->profile_photo ? $user->profile_photo_url : null,
                'profilePhotoPath' => $user->profile_photo,
            ];
        })->values()->all();

        $tasks = Task::whereHas('area.cycle', fn ($query) => $query->where('program_id', $this->id))->get();
        $totalTasks = $tasks->count();
        $completedTasks = $tasks->where('status', 'Completed')->count();
        $inProgressTasks = $tasks->filter(fn ($task) => in_array($task->status, ['Not Started', 'In Progress'], true))->count();
        $overdueTasks = $tasks->filter(fn ($task) => $task->due_date && $task->due_date->isPast() && $task->status !== 'Completed')->count();
        $completionRate = $totalTasks > 0 ? (int) round(($completedTasks / $totalTasks) * 100) : 0;

        $documents = Document::where('program_id', $this->id)->get();
        $totalDocuments = $documents->count();
        $draftDocuments = $documents->where('status', 'Draft')->count();
        $pendingReviewDocuments = $documents->whereIn('status', ['Draft', 'Revision Requested'])->count();
        $activeDocuments = $documents->where('status', 'Active')->count();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'chair' => $this->chair_name,
            'chairId' => $this->chair_id,
            'needsChairAssigned' => $this->needs_chair_assigned,
            'activeCycleId' => $this->active_cycle_id,
            'activeLevel' => $this->relationLoaded('activeCycle')
                ? $this->activeCycle?->level
                : $this->activeCycle?->level,
            'accreditationStatus' => $this->accreditation_status,
            'complianceScore' => $this->compliance_score,
            'collegeId' => $this->college_id,
            'faculty' => $faculty,
            'facultyCount' => $facultyUsers->count(),
            'requirementProgress' => [
                'totalTasks' => $totalTasks,
                'completedTasks' => $completedTasks,
                'inProgressTasks' => $inProgressTasks,
                'overdueTasks' => $overdueTasks,
                'completionRate' => $completionRate,
            ],
            'submissionStats' => [
                'totalDocuments' => $totalDocuments,
                'activeDocuments' => $activeDocuments,
                'draftDocuments' => $draftDocuments,
                'pendingReviewDocuments' => $pendingReviewDocuments,
            ],
            'college' => $this->whenLoaded('college', fn () => new CollegeResource($this->college)),
            'chairUser' => $this->whenLoaded('chairUser', fn () => new UserResource($this->chairUser)),
        ];
    }
}
