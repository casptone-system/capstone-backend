<?php

namespace App\Notifications;

use App\Models\FileRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FileRequestDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public FileRequest $fileRequest) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $to = $this->fileRequest->recipientProgram?->name ?: 'The other program';

        return (new MailMessage)
            ->subject('File request declined: '.$this->fileRequest->title)
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line($to.' declined your file request.')
            ->line('**Request:** '.$this->fileRequest->title)
            ->line('**Reason:** '.($this->fileRequest->decline_reason ?: 'No reason given.'))
            ->action('View sent requests', url('/user/dashboard/program-chair?section=file-requests&box=sent'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $to = $this->fileRequest->recipientProgram?->name ?: 'The other program';

        return [
            'type' => 'file_request_declined',
            'title' => 'File request declined',
            'message' => $to.' declined “'.$this->fileRequest->title.'”.',
            'file_request_id' => $this->fileRequest->id,
            'decline_reason' => $this->fileRequest->decline_reason,
            'action_url' => '/user/dashboard/program-chair?section=file-requests&box=sent',
        ];
    }
}
