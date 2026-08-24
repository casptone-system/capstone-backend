<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeanTaskAssignmentNotification extends Notification
{
    use Queueable;

    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $programName = $this->data['program_name'] ?? 'your program';
        $deanName = $this->data['dean_name'] ?? 'The Dean';
        $description = trim((string) ($this->data['description'] ?? ''));
        $fileName = $this->data['instrument_file_name'] ?? null;

        $message = "{$deanName} assigned accreditation work for {$programName}.";
        if (! empty($this->data['academic_year'])) {
            $message .= " Academic year: {$this->data['academic_year']}.";
        }
        if ($fileName) {
            $message .= " Attached instrument: {$fileName}.";
        }
        if ($description !== '') {
            $message .= " {$description}";
        }

        return [
            'type' => 'dean_task_assignment',
            'title' => "Accreditation task from {$deanName}",
            'message' => $message,
            'dean_name' => $deanName,
            'program_name' => $programName,
            'instrument_file_path' => $this->data['instrument_file_path'] ?? null,
            'instrument_file_name' => $fileName,
            'academic_year' => $this->data['academic_year'] ?? null,
            'description' => $description,
            'action_url' => '/user/dashboard/program-chair?section=notifications',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Accreditation Task Assignment from Dean - {$this->data['program_name']}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("The Dean has assigned you accreditation tasks for the **{$this->data['program_name']}** program.")
            ->line("**Academic Year:** {$this->data['academic_year']}");

        if (! empty($this->data['instrument_file_name'])) {
            $mail->line("**Attached Instrument:** {$this->data['instrument_file_name']}");
        }

        if (! empty($this->data['description'])) {
            $mail->line("**Additional Details:** {$this->data['description']}");
        }

        return $mail
            ->action('View Tasks', url('/user/dashboard/program-chair'))
            ->line('Please log in to your ADAMS dashboard to review the assigned requirements and begin the accreditation process.');
    }
}
