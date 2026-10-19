<?php

namespace App\Services;

use App\Jobs\IssueDesignationLetterJob;
use App\Models\AccreditationArea;
use App\Models\DesignationFile;
use App\Models\User;
use App\Notifications\AreaInChargeAssignedNotification;
use App\Support\DispatchAfterResponse;
use App\Support\RoleSlug;
use App\Support\ScopedCache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AreaAssignmentService
{
    /**
     * @return array{assigned: true, area: AccreditationArea}|array{assigned: false, requiresConfirmation: true, currentChair: ?array{id: int, name: string, email: string}}
     */
    public function assignChair(AccreditationArea $area, int $chairId, bool $confirmReassign = false, ?User $designatedBy = null): array
    {
        $area->load('chair');
        $currentChairId = $area->chair_id ? (int) $area->chair_id : null;

        if ($currentChairId && $currentChairId !== $chairId && ! $confirmReassign) {
            return [
                'assigned' => false,
                'requiresConfirmation' => true,
                'currentChair' => $area->chair ? [
                    'id' => $area->chair->id,
                    'name' => $area->chair->name,
                    'email' => $area->chair->email,
                ] : null,
            ];
        }

        $assignee = User::findOrFail($chairId);
        $this->assertBelongsToAreaProgram($assignee, $area);

        DB::transaction(function () use ($area, $chairId, $assignee) {
            $area->members()->where('user_id', $chairId)->delete();
            $area->update(['chair_id' => $chairId]);

            if (! $assignee->isAreaIncharge()) {
                $assignee->assignRole(RoleSlug::AREA_IN_CHARGE);
            }
        });

        $fresh = $area->fresh(['chair', 'members.user', 'cycle.program']);

        if ($currentChairId !== $chairId) {
            DispatchAfterResponse::job(new IssueDesignationLetterJob(
                $assignee->id,
                $area->id,
                DesignationFile::ROLE_CHAIR,
                $designatedBy?->id,
            ));

            $areaId = $area->id;
            $assigneeId = $assignee->id;
            $notify = function () use ($assigneeId, $areaId) {
                $user = User::query()->find($assigneeId);
                $assignedArea = AccreditationArea::query()->with('cycle.program')->find($areaId);
                if (! $user || ! $assignedArea) {
                    return;
                }
                $user->notify(
                    (new AreaInChargeAssignedNotification($assignedArea))->onQueue('mail')
                );
            };
            if (app()->runningUnitTests()) {
                $notify();
            } else {
                dispatch($notify)->afterResponse();
            }
        }

        $programId = (int) ($fresh->cycle?->program_id ?? 0);
        if ($programId > 0) {
            ScopedCache::bustProgram($programId, $fresh->cycle?->program?->college_id);
        }

        return [
            'assigned' => true,
            'area' => $fresh,
        ];
    }

    public function assertBelongsToAreaProgram(User $user, AccreditationArea $area): void
    {
        $programId = (int) $area->cycle()->value('program_id');

        if (! $user->belongsToProgram($programId) && ! $user->ownsAssignedProgram($programId)) {
            throw new InvalidArgumentException('The selected user does not belong to this program.');
        }
    }
}
