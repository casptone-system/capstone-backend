<?php

namespace App\Notifications;

use App\Models\Program;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProgramChairHandoverNotification extends Notification
{
    use Queueable;

    public function __construct(public Program $program, public $previousChair, public $newChair, public $changedBy, public bool $isNewReceiver = false) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->isNewReceiver) {
            return (new MailMessage)
                ->subject('You have been assigned as Program Chair')
                ->greeting('Hello ' . ($notifiable->name ?? ''))
                ->line('You have been assigned as Program Chair for: ' . $this->program->name)
                ->line('Assigned by: ' . ($this->changedBy->name ?? 'Dean'))
                ->action('View Program', url('/programs/' . $this->program->id));
        }

        return (new MailMessage)
            ->subject('You are no longer Program Chair')
            ->greeting('Hello ' . ($notifiable->name ?? ''))
            ->line('You are no longer the Program Chair for: ' . $this->program->name)
            ->line('This change was made by: ' . ($this->changedBy->name ?? 'Dean'))
            ->line('Your historical activity and documents have been preserved.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'program_chair_handover',
            'title' => $this->isNewReceiver ? 'Assigned as Program Chair' : 'Program Chair Removed',
            'message' => $this->isNewReceiver
                ? ('You have been assigned as Program Chair for ' . $this->program->name)
                : ('You are no longer Program Chair for ' . $this->program->name),
            'program_id' => $this->program->id,
            'previous_chair_id' => $this->previousChair?->id,
            'new_chair_id' => $this->newChair?->id,
            'changed_by' => $this->changedBy?->email,
        ];
    }
}
