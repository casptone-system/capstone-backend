<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public ?string $reviewerNotes = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Task Approved: ' . $this->task->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your submitted task has been approved by your Program Chair.')
            ->line('**Task:** ' . $this->task->title)
            ->line('**Area:** ' . ($this->task->area?->name ?? 'N/A'))
            ->line('**Status:** Approved');

        if ($this->reviewerNotes) {
            $mail->line('**Reviewer Notes:** ' . $this->reviewerNotes);
        }

        return $mail->action('View Task', url('/tasks/' . $this->task->id))
            ->line('Thank you for your submission.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_approved',
            'title' => 'Task Approved',
            'message' => 'Your task has been approved: ' . $this->task->title,
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'area_id' => $this->task->area_id,
            'status' => 'Approved',
            'reviewer_notes' => $this->reviewerNotes,
        ];
    }
}
