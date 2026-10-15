<?php

namespace App\Notifications;

use App\Models\FileRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FileRequestCreatedNotification extends Notification implements ShouldQueue
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
        $from = $this->fileRequest->requesterProgram?->name ?: 'Another program';
        $title = $this->fileRequest->title;

        return (new MailMessage)
            ->subject('File requested: '.$title)
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line($from.' asked your program for a file.')
            ->line('**Request:** '.$title)
            ->when($this->fileRequest->description, fn (MailMessage $mail) => $mail->line($this->fileRequest->description))
            ->when($this->fileRequest->needed_by, fn (MailMessage $mail) => $mail->line('**Needed by:** '.$this->fileRequest->needed_by->toDateString()))
            ->action('View file requests', url('/user/dashboard/program-chair?section=file-requests'))
            ->line('Sign in to upload the PDF or decline the request.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $from = $this->fileRequest->requesterProgram?->name ?: 'Another program';

        return [
            'type' => 'file_request_created',
            'title' => 'File requested',
            'message' => $from.' asked for “'.$this->fileRequest->title.'”.',
            'file_request_id' => $this->fileRequest->id,
            'action_url' => '/user/dashboard/program-chair?section=file-requests',
        ];
    }
}
