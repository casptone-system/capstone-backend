<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\User;
use App\Notifications\FacultyAreaAssignmentNotification;

class AreaAssignmentNotifier
{
    public static function notifyMember(
        User $faculty,
        AccreditationArea $area,
        User $assignedBy,
        ?string $deadline = null,
        ?string $instructions = null,
    ): void {
        $area->loadMissing('cycle.program');

        $faculty->notify(new FacultyAreaAssignmentNotification([
            'program_chair_name' => $assignedBy->name,
            'program_name' => $area->cycle?->program?->name ?? 'your program',
            'area_name' => $area->name,
            'area_id' => $area->id,
            'deadline' => $deadline
                ?: ($area->deadline?->format('Y-m-d') ?? now()->addDays(30)->format('Y-m-d')),
            'instructions' => $instructions ?? '',
        ]));
    }
}
