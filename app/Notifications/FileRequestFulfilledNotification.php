<?php

namespace App\Notifications;

use App\Models\FileRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FileRequestFulfilledNotification extends Notification implements ShouldQueue
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
            ->subject('File request fulfilled: '.$this->fileRequest->title)
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line($to.' uploaded the file you requested.')
            ->line('**Request:** '.$this->fileRequest->title)
            ->action('Download the file', url('/user/dashboard/program-chair?section=file-requests&box=sent'))
            ->line('The file is available from your Sent requests. It is not attached to an area.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $to = $this->fileRequest->recipientProgram?->name ?: 'The other program';

        return [
            'type' => 'file_request_fulfilled',
            'title' => 'File request fulfilled',
            'message' => $to.' uploaded “'.$this->fileRequest->title.'”.',
            'file_request_id' => $this->fileRequest->id,
            'action_url' => '/user/dashboard/program-chair?section=file-requests&box=sent',
        ];
    }
}
