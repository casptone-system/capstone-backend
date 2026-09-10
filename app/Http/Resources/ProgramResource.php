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
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'email', 'profile_photo', 'program_id']);

        $faculty = $facultyUsers->map(function (User $user): array {
            return [
                'id' => $user->id,
                'name' => trim(sprintf('%s %s %s', $user->first_name, $user->middle_name ?? '', $user->last_name)),
                'email' => $user->email,
                'profilePhoto' => $user->profile_photo ? $user->profile_photo_url : null,
                'profilePhotoPath' => $user->profile_photo,
            ];
        })->values()->all();

        $taskQuery = Task::query()->where(function ($query) {
            $query->where('program_id', $this->id)
                ->orWhereHas('area.cycle', fn ($cycle) => $cycle->where('program_id', $this->id));
        });
        $totalTasks = (clone $taskQuery)->count();
        $completedTasks = (clone $taskQuery)->where('status', 'Completed')->count();
        $inProgressTasks = (clone $taskQuery)->whereIn('status', ['Not Started', 'In Progress'])->count();
        $overdueTasks = (clone $taskQuery)
            ->where('due_date', '<', now())
            ->where('status', '!=', 'Completed')
            ->count();
        $completionRate = $totalTasks > 0 ? (int) round(($completedTasks / $totalTasks) * 100) : 0;

        $documentQuery = Document::query()->where('program_id', $this->id);
        $totalDocuments = (clone $documentQuery)->count();
        $draftDocuments = (clone $documentQuery)->where('status', 'Draft')->count();
        $pendingReviewDocuments = (clone $documentQuery)->whereIn('status', ['Draft', 'Revision Requested'])->count();
        $activeDocuments = (clone $documentQuery)->where('status', 'Active')->count();

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
