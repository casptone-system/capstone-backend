<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FacultyAreaAssignmentNotification extends Notification implements ShouldQueue
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
            'type' => 'faculty_area_assignment',
            'program_chair_name' => $this->data['program_chair_name'],
            'program_name' => $this->data['program_name'],
            'area_name' => $this->data['area_name'],
            'deadline' => $this->data['deadline'],
            'instructions' => $this->data['instructions'],
            'action_url' => '/user/dashboard/faculty#submission',
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
            ->when($this->data['instructions'], fn ($mail) => $mail->line("**Instructions:** {$this->data['instructions']}"))
            ->action('Submit Evidence', url('/user/dashboard/faculty'))
            ->line('Please log in to your ADAMS dashboard to upload required documents and complete this accreditation task.');
    }
}
