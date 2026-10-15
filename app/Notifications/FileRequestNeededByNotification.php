<?php

namespace App\Notifications;

use App\Models\FileRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FileRequestNeededByNotification extends Notification implements ShouldQueue
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
        $date = $this->fileRequest->needed_by?->toDateString();

        return (new MailMessage)
            ->subject('File request still pending: '.$this->fileRequest->title)
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line($from.' still needs “'.$this->fileRequest->title.'”.')
            ->when($date, fn (MailMessage $mail) => $mail->line('**Needed by:** '.$date))
            ->action('View file requests', url('/user/dashboard/program-chair?section=file-requests'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'file_request_needed_by',
            'title' => 'File request needed by today',
            'message' => '“'.$this->fileRequest->title.'” is still pending.',
            'file_request_id' => $this->fileRequest->id,
            'needed_by' => $this->fileRequest->needed_by?->toDateString(),
            'action_url' => '/user/dashboard/program-chair?section=file-requests',
        ];
    }
}
