<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FacultyAreaAssignmentNotification extends Notification
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
        $areaName = $this->data['area_name'] ?? 'an accreditation area';
        $programName = $this->data['program_name'] ?? 'your program';
        $deadline = $this->data['deadline'] ?? null;
        $instructions = trim((string) ($this->data['instructions'] ?? ''));

        $message = "You have been assigned to {$areaName} for {$programName}.";
        if ($deadline) {
            $message .= " Deadline: {$deadline}.";
        }
        if ($instructions !== '') {
            $message .= " {$instructions}";
        }

        return [
            'type' => 'faculty_area_assignment',
            'title' => "Assigned to {$areaName}",
            'message' => $message,
            'program_chair_name' => $this->data['program_chair_name'] ?? null,
            'program_name' => $programName,
            'area_name' => $areaName,
            'area_id' => $this->data['area_id'] ?? null,
            'deadline' => $deadline,
            'instructions' => $instructions,
            'action_url' => '/user/dashboard/faculty?section=areas',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New Area Assignment - {$this->data['area_name']} ({$this->data['program_name']})")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("You have been assigned to work on the following accreditation area:")
            ->line("**Program:** {$this->data['program_name']}")
            ->line("**Area:** {$this->data['area_name']}")
            ->line("**Deadline:** {$this->data['deadline']}")
            ->when($this->data['instructions'] ?? null, fn ($mail) => $mail->line("**Instructions:** {$this->data['instructions']}"))
            ->action('Submit Evidence', url('/user/dashboard/faculty'))
            ->line('Please log in to your ADAMS dashboard to upload required documents and complete this accreditation task.');
    }
}
