<?php

namespace App\Notifications;

use App\Models\AccreditationArea;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AreaInChargeAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AccreditationArea $area)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->area->loadMissing('cycle.program');
        $cycle = $this->area->cycle;
        $programName = $cycle?->program?->name ?? 'your program';
        $level = $cycle?->level;
        $mail = (new MailMessage)
            ->subject("Assigned as Area In-Charge — {$this->area->name}")
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name ?: ''))
            ->line("You have been assigned as Area In-Charge for {$this->area->name}.")
            ->line("Program: {$programName}");

        if ($level) {
            $mail->line("Accreditation level: {$level}");
        }

        return $mail
            ->action('Open Faculty Dashboard', url('/user/dashboard/faculty'))
            ->line('Please log in to ADAMS to review the area and begin gathering evidence.');
    }

    public function toArray(object $notifiable): array
    {
        $this->area->loadMissing('cycle.program');
        $cycle = $this->area->cycle;

        return [
            'type' => 'accreditation_area_assigned',
            'title' => 'Accreditation area assigned',
            'message' => "You are assigned as Area In-Charge for {$this->area->name}.",
            'area_id' => $this->area->id,
            'area_name' => $this->area->name,
            'cycle_id' => $cycle?->id,
            'level' => $cycle?->level,
            'program_id' => $cycle?->program_id,
            'program_name' => $cycle?->program?->name,
            'instrument_id' => $this->area->instrument_id,
        ];
    }
}
