<?php

namespace App\Notifications;

use App\Models\AccreditationArea;
use Illuminate\Notifications\Notification;

class AreaInChargeAssignedNotification extends Notification
{
    public function __construct(public AccreditationArea $area)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cycle = $this->area->cycle;

        return [
            'type' => 'accreditation_area_assigned',
            'title' => 'Accreditation area assigned',
            'message' => "You are assigned to {$this->area->name}.",
            'area_id' => $this->area->id,
            'cycle_id' => $cycle?->id,
            'program_id' => $cycle?->program_id,
            'instrument_id' => $this->area->instrument_id,
        ];
    }
}
