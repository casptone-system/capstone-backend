<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskReturnedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public string $returnReason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Task Returned for Revision: ' . $this->task->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your submitted task has been returned for revision by your Program Chair.')
            ->line('**Task:** ' . $this->task->title)
            ->line('**Area:** ' . ($this->task->area?->name ?? 'N/A'))
            ->line('**Reason:** ' . $this->returnReason)
            ->line('Please review the feedback and resubmit your task.')
            ->action('View Task', url('/tasks/' . $this->task->id))
            ->line('Thank you for your attention to this matter.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_returned',
            'title' => 'Task Returned for Revision',
            'message' => 'Your task has been returned for revision: ' . $this->task->title,
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'area_id' => $this->task->area_id,
            'status' => 'Returned',
            'return_reason' => $this->returnReason,
        ];
    }
}
