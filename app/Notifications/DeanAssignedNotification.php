<?php

namespace App\Notifications;

use App\Models\College;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeanAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public College $college,
        public $assignedBy = null,
        public ?User $targetUser = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isAdminView = $this->assignedBy && $notifiable->id === $this->assignedBy->id;

        if ($isAdminView) {
            return (new MailMessage)
                ->subject('Dean assigned to ' . $this->college->name)
                ->greeting('Hello ' . ($notifiable->name ?? ''))
                ->line(($this->targetUser?->name ?? 'The selected user') . ' is now the Dean of ' . $this->college->name . '.')
                ->line('This assignment was made from the Super Administrator dashboard.');
        }

        return (new MailMessage)
            ->subject('You have been assigned as Dean')
            ->greeting('Hello ' . ($notifiable->name ?? ''))
            ->line('You have been assigned as Dean of ' . $this->college->name . '.')
            ->line('Assigned by: ' . ($this->assignedBy?->name ?? $this->assignedBy?->email ?? 'the system'))
            ->action('Open Dashboard', url('/user/dashboard/dean'))
            ->line('You can now access the dean dashboard for this college.');
    }

    public function toArray(object $notifiable): array
    {
        $isAdminView = $this->assignedBy && $notifiable->id === $this->assignedBy->id;
        $targetName = $this->targetUser?->name ?? 'Selected user';

        return [
            'type' => 'dean_assigned',
            'title' => $isAdminView ? 'Dean assigned' : 'Assigned as Dean',
            'message' => $isAdminView
                ? 'You assigned ' . $targetName . ' as Dean of ' . $this->college->name
                : 'You have been assigned as Dean of ' . $this->college->name,
            'college_id' => $this->college->id,
            'college_name' => $this->college->name,
            'assigned_by' => $this->assignedBy?->email ?? null,
            'target_user_name' => $targetName,
        ];
    }
}
