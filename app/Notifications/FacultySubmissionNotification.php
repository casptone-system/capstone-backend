<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FacultySubmissionNotification extends Notification implements ShouldQueue
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
            'type' => 'faculty_submission',
            'faculty_name' => $this->data['faculty_name'],
            'area_name' => $this->data['area_name'],
            'program_name' => $this->data['program_name'],
            'file_count' => $this->data['file_count'],
            'submitted_at' => $this->data['submitted_at'],
            'action_url' => '/user/dashboard/program-chair#review',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New Accreditation Area Submission - {$this->data['area_name']}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("{$this->data['faculty_name']} has submitted evidence files for the accreditation area.")
            ->line("**Program:** {$this->data['program_name']}")
            ->line("**Area:** {$this->data['area_name']}")
            ->line("**Files Submitted:** {$this->data['file_count']}")
            ->line("**Submitted At:** {$this->data['submitted_at']}")
            ->action('Review Submission', url('/user/dashboard/program-chair'))
            ->line('Please log in to your ADAMS dashboard to review and provide feedback.');
    }
}
