<?php

namespace App\Notifications;

use App\Models\AccreditationCycle;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EvidenceReleasedToAccreditorNotification extends Notification
{
    use Queueable;

    public function __construct(public AccreditationCycle $cycle) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->cycle->loadMissing('program.college');
        $program = $this->cycle->program?->name ?: 'A program';
        $college = $this->cycle->program?->college?->name;
        $level = $this->cycle->level ?: 'accreditation';
        $where = $college ? $program.' ('.$college.')' : $program;

        return [
            'type' => 'evidence_released_to_accreditor',
            'title' => 'Accreditation evidence submitted',
            'message' => $where.' submitted '.$level.' evidence for your review.',
            'action_url' => '/user/dashboard/accreditor?section=submitted-evidence',
            'program_id' => $this->cycle->program_id,
            'cycle_id' => $this->cycle->id,
            'college_id' => $this->cycle->program?->college_id ?: $this->cycle->college_id,
        ];
    }
}
