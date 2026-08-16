<?php

namespace App\Notifications;

use App\Models\AccreditationCycle;
use App\Models\User;
use Illuminate\Notifications\Notification;

class AccreditationCycleNoticeNotification extends Notification
{
    public function __construct(
        public AccreditationCycle $cycle,
        public User $sentBy,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $program = $this->cycle->program()->first();

        return [
            'type' => 'accreditation_cycle_notice',
            'title' => 'Accreditation notice sent',
            'message' => $program
                ? $program->name . ' has been scheduled for ' . $this->cycle->level . ' accreditation.'
                : 'A program has been scheduled for accreditation.',
            'cycle_id' => $this->cycle->id,
            'program_id' => $this->cycle->program_id,
            'college_id' => $this->cycle->college_id,
            'level' => $this->cycle->level,
            'phase' => $this->cycle->phase,
            'scheduled_visit' => $this->cycle->scheduled_visit?->toDateString(),
            'valid_until' => $this->cycle->valid_until?->toDateString(),
            'instrument_name' => $this->cycle->instrument_name,
            'sent_by' => $this->sentBy->name,
        ];
    }
}
