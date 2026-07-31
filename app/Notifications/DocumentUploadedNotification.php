<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentUploadedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Document $document,
        public ?string $uploadedByName = null,
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
        $uploaderName = $this->uploadedByName ?? ($this->document->uploader?->name ?? 'A member');

        return (new MailMessage)
            ->subject('New Document Uploaded: ' . $this->document->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A new document has been uploaded by ' . $uploaderName . '.')
            ->line('**Document:** ' . $this->document->title)
            ->when($this->document->description, fn ($mail) => $mail->line('**Description:** ' . $this->document->description))
            ->when($this->document->school_year, fn ($mail) => $mail->line('**School Year:** ' . $this->document->school_year))
            ->line('**Version:** ' . $this->document->current_version)
            ->action('View Document', url('/documents/' . $this->document->id))
            ->line('Please review the uploaded document at your earliest convenience.');
    }

    /**
     * Get the array representation of the notification (stored in database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_uploaded',
            'title' => 'New Document Uploaded',
            'message' => 'A new document has been uploaded: ' . $this->document->title,
            'document_id' => $this->document->id,
            'document_title' => $this->document->title,
            'school_year' => $this->document->school_year,
            'current_version' => $this->document->current_version,
            'uploaded_by' => $this->uploadedByName ?? $this->document->uploader?->name,
            'program_id' => $this->document->program_id,
            'area_id' => $this->document->area_id,
            'task_id' => $this->document->task_id,
        ];
    }
}