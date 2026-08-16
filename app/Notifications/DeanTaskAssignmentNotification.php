<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeanTaskAssignmentNotification extends Notification implements ShouldQueue
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

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'dean_task_assignment',
            'dean_name' => $this->data['dean_name'],
            'program_name' => $this->data['program_name'],
            'instrument_file_path' => $this->data['instrument_file_path'] ?? null,
            'instrument_file_name' => $this->data['instrument_file_name'] ?? null,
            'academic_year' => $this->data['academic_year'],
            'description' => $this->data['description'],
            'action_url' => '/user/dashboard/program-chair',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Accreditation Task Assignment from Dean - {$this->data['program_name']}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("The Dean has assigned you accreditation tasks for the **{$this->data['program_name']}** program.")
            ->line("**Academic Year:** {$this->data['academic_year']}");

        if (!empty($this->data['instrument_file_name'])) {
            $mail->line("**Attached Instrument:** {$this->data['instrument_file_name']}");
        }

        if (!empty($this->data['description'])) {
            $mail->line("**Additional Details:** {$this->data['description']}");
        }

        return $mail
            ->action('View Tasks', url('/user/dashboard/program-chair'))
            ->line('Please log in to your ADAMS dashboard to review the assigned requirements and begin the accreditation process.');
    }
}
