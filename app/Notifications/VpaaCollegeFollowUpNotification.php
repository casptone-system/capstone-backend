<?php

namespace App\Notifications;

use App\Models\AccreditationCycle;
use App\Models\User;
use Illuminate\Notifications\Notification;

class VpaaCollegeFollowUpNotification extends Notification
{
    /**
     * @param  list<string>  $issues
     */
    public function __construct(
        public AccreditationCycle $cycle,
        public User $sentBy,
        public string $note,
        public array $issues = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $program = $this->cycle->program?->name ?? 'A program';
        $level = $this->cycle->level ?: 'the current level';

        return [
            'type' => 'warning',
            'title' => 'VPAA follow-up: '.$program,
            'message' => $this->note !== ''
                ? $this->note
                : $program.' needs college action on '.$level.'.',
            'issues' => $this->issues,
            'cycle_id' => $this->cycle->id,
            'program_id' => $this->cycle->program_id,
            'college_id' => $this->cycle->program?->college_id ?? $this->cycle->college_id,
            'level' => $this->cycle->level,
            'action_url' => '/user/dashboard/dean',
            'sent_by' => $this->sentBy->name,
        ];
    }
}
