<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AccreditationRequirement;
use App\Models\AccreditationWorkspace;
use App\Models\CriterionEvidence;
use App\Models\InstrumentTemplate;
use App\Models\Program;
use App\Models\RoleStorageFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccreditationWorkspaceService
{
    public function createForProgramChair(User $user, string $level, ?string $deadline): AccreditationWorkspace
    {
        if (! $user->isProgramChair()) {
            abort(403, 'Only a Program Chair can create an accreditation folder.');
        }

        $program = $user->assignedProgram();
        if (! $program) {
            abort(403, 'You are not assigned to a program.');
        }

        $template = InstrumentTemplate::with('areas.parameters.criteria')
            ->where('level', $level)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            abort(422, 'No accreditation template exists for this level yet. Ask QA or VPAA/DI to set it up.');
        }

        return DB::transaction(function () use ($user, $program, $template, $level, $deadline) {
            $cycle = AccreditationCycle::firstOrCreate(
                [
                    'program_id' => $program->id,
                    'level' => $level,
                ],
                [
                    'college_id' => $program->college_id,
                    'status' => 'Preparation',
                    'phase' => 'Faculty Assignment',
                    'workflow_status' => 'Forwarded to Chair',
                    'scheduled_visit' => $deadline,
                    'program_chair_id' => $user->id,
                ]
            );

            $this->cloneTemplateOntoCycle($template, $cycle);

            $folderName = trim($level.' · '.($deadline ?: now()->toDateString()));
            $workspace = AccreditationWorkspace::create([
                'program_id' => $program->id,
                'cycle_id' => $cycle->id,
                'template_id' => $template->id,
                'created_by' => $user->id,
                'name' => $folderName,
                'level' => $level,
                'deadline' => $deadline,
                'status' => 'active',
            ]);

            $root = RoleStorageFolder::create([
                'user_id' => $user->id,
                'program_id' => $program->id,
                'workspace_id' => $workspace->id,
                'role' => 'program-chair',
                'name' => $folderName,
                'folder_kind' => 'root',
            ]);

            $areas = AccreditationArea::where('cycle_id', $cycle->id)->with('parameters')->orderBy('name')->get();
            foreach ($areas as $area) {
                $areaFolder = RoleStorageFolder::create([
                    'user_id' => $user->id,
                    'program_id' => $program->id,
                    'parent_id' => $root->id,
                    'workspace_id' => $workspace->id,
                    'area_id' => $area->id,
                    'role' => 'program-chair',
                    'name' => $area->name,
                    'folder_kind' => 'area',
                ]);

                foreach ($area->parameters as $parameter) {
                    RoleStorageFolder::create([
                        'user_id' => $user->id,
                        'program_id' => $program->id,
                        'parent_id' => $areaFolder->id,
                        'workspace_id' => $workspace->id,
                        'area_id' => $area->id,
                        'parameter_id' => $parameter->id,
                        'role' => 'program-chair',
                        'name' => 'Parameter '.$parameter->code,
                        'folder_kind' => 'parameter',
                    ]);
                }

                RoleStorageFolder::create([
                    'user_id' => $user->id,
                    'program_id' => $program->id,
                    'parent_id' => $areaFolder->id,
                    'workspace_id' => $workspace->id,
                    'area_id' => $area->id,
                    'role' => 'program-chair',
                    'name' => 'Done',
                    'folder_kind' => 'done',
                ]);
            }

            $workspace->update(['root_folder_id' => $root->id]);

            return $workspace->fresh(['cycle', 'program']);
        });
    }

    public function cloneTemplateOntoCycle(InstrumentTemplate $template, AccreditationCycle $cycle): void
    {
        if (AccreditationArea::where('cycle_id', $cycle->id)->whereHas('parameters')->exists()) {
            return;
        }

        foreach ($template->areas as $templateArea) {
            $area = AccreditationArea::firstOrCreate(
                [
                    'cycle_id' => $cycle->id,
                    'name' => $templateArea->name,
                ],
                [
                    'description' => $templateArea->description,
                    'status' => 'Not Started',
                ]
            );

            foreach ($templateArea->parameters as $templateParameter) {
                $parameter = AccreditationParameter::firstOrCreate(
                    [
                        'area_id' => $area->id,
                        'code' => $templateParameter->code,
                    ],
                    [
                        'name' => $templateParameter->name,
                        'sort_order' => $templateParameter->sort_order,
                    ]
                );

                foreach ($templateParameter->criteria as $index => $criterion) {
                    $code = $templateParameter->code.'.'.($index + 1);
                    AccreditationRequirement::firstOrCreate(
                        [
                            'area_id' => $area->id,
                            'code' => $code,
                        ],
                        [
                            'parameter_id' => $parameter->id,
                            'title' => $criterion->title,
                            'description' => $criterion->description,
                            'required_evidence_type' => $criterion->evidence_type,
                            'status' => 'Not Started',
                        ]
                    );
                }
            }
        }
    }

    public function serializeWorkspace(AccreditationWorkspace $workspace, ?User $viewer = null): array
    {
        $workspace->load(['program', 'cycle']);
        $areas = AccreditationArea::with(['chair', 'members.user', 'parameters.criteria'])
            ->where('cycle_id', $workspace->cycle_id)
            ->orderBy('name')
            ->get();

        if ($viewer && ($viewer->isFaculty() || $viewer->isAreaIncharge()) && ! $viewer->isProgramChair()) {
            $areas = $areas->filter(fn (AccreditationArea $area) => $viewer->isAssignedToArea($area))->values();
        }

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'level' => $workspace->level,
            'deadline' => $workspace->deadline?->toDateString(),
            'accreditationDate' => $workspace->accreditation_date?->toDateString() ?? $workspace->deadline?->toDateString(),
            'phase' => $workspace->phase ?: $workspace->cycle?->phase,
            'workflowStatus' => $workspace->cycle?->workflow_status,
            'program' => [
                'id' => $workspace->program?->id,
                'name' => $workspace->program?->name,
                'code' => $workspace->program?->code,
            ],
            'areas' => $areas->map(fn (AccreditationArea $area) => $this->serializeArea($area, $workspace))->values(),
            'overallProgress' => $this->overallProgress($areas, $workspace),
        ];
    }

    public function serializeArea(AccreditationArea $area, AccreditationWorkspace $workspace): array
    {
        $parameters = $area->parameters->map(function (AccreditationParameter $parameter) use ($workspace, $area) {
            $criteria = $parameter->criteria->map(function (AccreditationRequirement $criterion) use ($workspace) {
                $evidence = CriterionEvidence::where('requirement_id', $criterion->id)
                    ->where('workspace_id', $workspace->id)
                    ->latest()
                    ->get();
                $done = $evidence->contains(fn ($item) => $item->is_done);

                return [
                    'id' => $criterion->id,
                    'title' => $criterion->title,
                    'description' => $criterion->description,
                    'evidenceType' => $criterion->required_evidence_type ?: 'system',
                    'isDone' => $done,
                    'files' => $evidence->map(fn (CriterionEvidence $item) => [
                        'id' => $item->id,
                        'name' => $item->original_name,
                        'evidenceType' => $item->evidence_type,
                        'isDone' => $item->is_done,
                        'uploadedBy' => $item->uploaded_by,
                    ])->values(),
                ];
            });

            $total = max($criteria->count(), 1);
            $completed = $criteria->where('isDone', true)->count();

            return [
                'id' => $parameter->id,
                'code' => $parameter->code,
                'name' => $parameter->name,
                'progress' => (int) round(($completed / $total) * 100),
                'criteria' => $criteria->values(),
            ];
        });

        $areaProgress = $parameters->isEmpty() ? 0 : (int) round($parameters->avg('progress'));

        return [
            'id' => $area->id,
            'name' => $area->name,
            'description' => $area->description,
            'status' => $area->status,
            'progress' => $areaProgress,
            'chair' => $area->chair ? [
                'id' => $area->chair->id,
                'name' => $area->chair->name,
                'email' => $area->chair->email,
                'photo' => $area->chair->profile_photo_url,
            ] : null,
            'members' => $area->members->map(fn ($member) => [
                'id' => $member->id,
                'userId' => $member->user_id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'photo' => $member->user?->profile_photo_url,
            ])->values(),
            'parameters' => $parameters->values(),
        ];
    }

    public function overallProgress($areas, AccreditationWorkspace $workspace): int
    {
        if ($areas->isEmpty()) {
            return 0;
        }

        $values = $areas->map(fn (AccreditationArea $area) => $this->serializeArea($area->loadMissing(['parameters.criteria', 'chair', 'members.user']), $workspace)['progress']);

        return (int) round($values->avg());
    }

    public function storeEvidence(User $user, AccreditationRequirement $requirement, $file, AccreditationWorkspace $workspace): CriterionEvidence
    {
        $area = $requirement->area;
        if (! $user->isAssignedToArea($area) && ! $user->isProgramChair()) {
            abort(403, 'You are not assigned to this area.');
        }

        $path = $file->store("accreditation/workspace-{$workspace->id}/criteria-{$requirement->id}", 'private');

        $evidence = CriterionEvidence::create([
            'requirement_id' => $requirement->id,
            'parameter_id' => $requirement->parameter_id,
            'area_id' => $area->id,
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'evidence_type' => $requirement->required_evidence_type ?: 'system',
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'is_done' => false,
        ]);

        $requirement->update(['status' => 'In Progress']);
        $area->update(['status' => 'In Progress']);

        return $evidence;
    }

    public function markDone(User $user, AccreditationRequirement $requirement, AccreditationWorkspace $workspace): void
    {
        $area = $requirement->area;
        if (! $user->isAssignedToArea($area) && ! $user->isProgramChair()) {
            abort(403, 'You are not assigned to this area.');
        }

        $hasFile = CriterionEvidence::where('requirement_id', $requirement->id)
            ->where('workspace_id', $workspace->id)
            ->exists();

        if (! $hasFile) {
            abort(422, 'Attach a file before marking this criterion as done.');
        }

        CriterionEvidence::where('requirement_id', $requirement->id)
            ->where('workspace_id', $workspace->id)
            ->update([
                'is_done' => true,
                'marked_done_at' => now(),
            ]);

        $requirement->update(['status' => 'Completed']);
    }
}
