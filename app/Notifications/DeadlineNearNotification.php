<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineNearNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Task $task,
        public int $daysRemaining = 0,
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
        $dueDate = $this->task->due_date;
        $urgency = $this->daysRemaining <= 1 ? 'URGENT' : 'Reminder';

        return (new MailMessage)
            ->subject("[{$urgency}] Task Deadline Approaching: " . $this->task->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('This is a reminder that your task deadline is approaching.')
            ->line('**Task:** ' . $this->task->title)
            ->line('**Priority:** ' . $this->task->priority)
            ->line('**Status:** ' . $this->task->status)
            ->when($dueDate, fn ($mail) => $mail->line('**Due Date:** ' . $dueDate->format('F j, Y')))
            ->line('**Days Remaining:** ' . $this->daysRemaining)
            ->action('View Task', url('/tasks/' . $this->task->id))
            ->line('Please ensure the task is completed before the deadline.');
    }

    /**
     * Get the array representation of the notification (stored in database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'deadline_near',
            'title' => 'Task Deadline Approaching',
            'message' => 'Task "' . $this->task->title . '" is due in ' . $this->daysRemaining . ' day(s).',
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'priority' => $this->task->priority,
            'status' => $this->task->status,
            'due_date' => $this->task->due_date?->toDateString(),
            'days_remaining' => $this->daysRemaining,
            'area_id' => $this->task->area_id,
        ];
    }
}