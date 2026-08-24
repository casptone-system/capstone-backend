<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FacultySubmissionNotification extends Notification
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
        $facultyName = $this->data['faculty_name'] ?? 'A faculty member';
        $areaName = $this->data['area_name'] ?? 'an accreditation area';
        $programName = $this->data['program_name'] ?? 'your program';
        $fileCount = $this->data['file_count'] ?? 0;

        return [
            'type' => 'faculty_submission',
            'title' => "New submission for {$areaName}",
            'message' => "{$facultyName} submitted {$fileCount} file(s) for {$areaName} in {$programName}.",
            'faculty_name' => $facultyName,
            'area_name' => $areaName,
            'program_name' => $programName,
            'file_count' => $fileCount,
            'submitted_at' => $this->data['submitted_at'] ?? null,
            'action_url' => '/user/dashboard/program-chair?section=review',
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
