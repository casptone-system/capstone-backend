<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\User;
use App\Notifications\AreaInChargeAssignedNotification;
use App\Support\RoleSlug;
use InvalidArgumentException;

class AreaAssignmentService
{
    public function __construct(private AreaProgressService $progress)
    {
    }

    /**
     * @return array{assigned: true, area: AccreditationArea}|array{assigned: false, requiresConfirmation: true, currentChair: ?array{id: int, name: string, email: string}}
     */
    public function assignChair(AccreditationArea $area, int $chairId, bool $confirmReassign = false): array
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

        $area->members()->where('user_id', $chairId)->delete();
        $area->update(['chair_id' => $chairId]);

        if (! $assignee->isAreaIncharge()) {
            $assignee->assignRole(RoleSlug::AREA_IN_CHARGE);
        }

        if ($currentChairId !== $chairId) {
            $assignee->notify(new AreaInChargeAssignedNotification(
                $area->fresh(['cycle.program'])
            ));
        }

        $this->progress->refresh($area->fresh());

        return [
            'assigned' => true,
            'area' => $area->load('chair', 'members.user'),
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
