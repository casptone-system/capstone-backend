<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Task $task,
        public ?string $assignedBy = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $creatorName = $this->assignedBy ?? ($this->task->creator?->name ?? 'A coordinator');

        return (new MailMessage)
            ->subject('New Task Assigned: ' . $this->task->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('You have been assigned to a new task by ' . $creatorName . '.')
            ->line('**Task:** ' . $this->task->title)
            ->line('**Priority:** ' . $this->task->priority)
            ->line('**Status:** ' . $this->task->status)
            ->when($this->task->due_date, fn ($mail) => $mail->line('**Due Date:** ' . $this->task->due_date->format('F j, Y')))
            ->when($this->task->description, fn ($mail) => $mail->line('**Description:** ' . $this->task->description))
            ->action('View Task', url('/tasks/' . $this->task->id))
            ->line('Please review the task details and begin work as soon as possible.');
    }

    /**
     * Get the array representation of the notification (stored in database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_assigned',
            'title' => 'New Task Assigned',
            'message' => 'You have been assigned to: ' . $this->task->title,
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'priority' => $this->task->priority,
            'due_date' => $this->task->due_date?->toDateString(),
            'assigned_by' => $this->assignedBy ?? $this->task->creator?->name,
            'area_id' => $this->task->area_id,
        ];
    }
}