<?php

namespace App\Notifications;

use App\Models\BoardOfActionDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BoardOfActionUploadedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public BoardOfActionDocument $document,
        public bool $replaced = false,
        public ?string $uploadedByName = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $programName = $this->document->program?->name ?: 'your program';
        $verb = $this->replaced ? 'replaced' : 'uploaded';
        $title = $this->document->displayTitle();

        return (new MailMessage)
            ->subject('Board of Action '.$verb.': '.$programName)
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line('Quality Assurance '.$verb.' a Board of Action document for '.$programName.'.')
            ->line('**Document:** '.$title)
            ->line('**Version:** '.$this->document->current_version)
            ->action('View Board of Action', url('/user/dashboard/program-chair?section=board-of-action'))
            ->line('Sign in to view and download the PDF.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $programName = $this->document->program?->name ?: 'your program';
        $verb = $this->replaced ? 'replaced' : 'uploaded';

        return [
            'type' => 'board_of_action_uploaded',
            'title' => $this->replaced ? 'Board of Action replaced' : 'Board of Action uploaded',
            'message' => 'A Board of Action document was '.$verb.' for '.$programName.'.',
            'board_of_action_document_id' => $this->document->id,
            'program_id' => $this->document->program_id,
            'program_name' => $programName,
            'document_title' => $this->document->displayTitle(),
            'current_version' => $this->document->current_version,
            'replaced' => $this->replaced,
            'uploaded_by' => $this->uploadedByName,
            'action_url' => '/user/dashboard/program-chair?section=board-of-action',
        ];
    }
}
