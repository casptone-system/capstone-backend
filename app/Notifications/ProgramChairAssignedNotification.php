<?php

namespace App\Notifications;

use App\Models\Program;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProgramChairAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(public Program $program, public $assignedBy) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been assigned as Program Chair')
            ->greeting('Hello ' . ($notifiable->name ?? ''))
            ->line('You have been assigned as Program Chair for: ' . $this->program->name)
            ->line('Assigned by: ' . ($this->assignedBy->name ?? 'Dean'))
            ->action('View Program', url('/programs/' . $this->program->id))
            ->line('You can access the program after completing your account setup.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'program_chair_assigned',
            'title' => 'Assigned as Program Chair',
            'message' => 'You have been assigned as Program Chair for ' . $this->program->name,
            'program_id' => $this->program->id,
            'assigned_by' => $this->assignedBy?->email ?? null,
        ];
    }
}
