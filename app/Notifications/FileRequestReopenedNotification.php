<?php

namespace App\Notifications;

use App\Models\FileRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FileRequestReopenedNotification extends Notification implements ShouldQueue
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
            ->subject('Requested file removed: '.$this->fileRequest->title)
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line($to.' removed the last file for a request that had been fulfilled.')
            ->line('**Request:** '.$this->fileRequest->title)
            ->line('The request is pending again.')
            ->action('View sent requests', url('/user/dashboard/program-chair?section=file-requests&box=sent'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'file_request_reopened',
            'title' => 'Requested file removed',
            'message' => 'The file for “'.$this->fileRequest->title.'” was removed. The request is pending again.',
            'file_request_id' => $this->fileRequest->id,
            'action_url' => '/user/dashboard/program-chair?section=file-requests&box=sent',
        ];
    }
}
