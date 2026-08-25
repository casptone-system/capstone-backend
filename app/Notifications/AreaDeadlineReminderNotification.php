<?php

namespace App\Notifications;

use App\Models\AccreditationArea;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AreaDeadlineReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public AccreditationArea $area,
        public string $kind,
        public int $daysRemaining,
        public bool $isOverdue = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $areaName = $this->area->name;
        $deadline = $this->area->deadline?->format('F j, Y');
        $progress = (int) ($this->area->progress_percent ?? 0);

        $mail = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line($this->bodyLine())
            ->line('**Area:** '.$areaName)
            ->line('**Deadline:** '.$deadline)
            ->line('**Current progress:** '.$progress.'%');

        if ($this->isOverdue) {
            $mail->line('This area is overdue and still incomplete. Please finish remaining evidence uploads.');
        } else {
            $mail->line('Please complete remaining PDF uploads before the deadline.');
        }

        return $mail
            ->action('Open My Areas', url('/user/dashboard/faculty?section=areas'))
            ->line('You are receiving this because you are assigned to this accreditation area.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'area_deadline_reminder',
            'title' => $this->subjectLine(),
            'message' => $this->bodyLine(),
            'area_id' => $this->area->id,
            'area_name' => $this->area->name,
            'deadline' => $this->area->deadline?->toDateTimeString(),
            'deadline_date' => $this->area->deadline?->timezone('Asia/Manila')->toDateString(),
            'progress_percent' => (int) ($this->area->progress_percent ?? 0),
            'reminder_kind' => $this->kind,
            'days_remaining' => $this->daysRemaining,
            'is_overdue' => $this->isOverdue,
            'action_url' => '/user/dashboard/faculty?section=areas',
        ];
    }

    private function subjectLine(): string
    {
        $areaName = $this->area->name;

        return match ($this->kind) {
            '7_days' => "Reminder: {$areaName} deadline in 7 days",
            'upcoming' => "Reminder: {$areaName} deadline in {$this->daysRemaining} days",
            '1_day' => "URGENT: {$areaName} deadline is tomorrow",
            'deadline_day' => "URGENT: {$areaName} deadline is today",
            'overdue' => "Overdue: {$areaName} is still incomplete",
            default => "Deadline reminder: {$areaName}",
        };
    }

    private function bodyLine(): string
    {
        $areaName = $this->area->name;

        return match ($this->kind) {
            '7_days' => "{$areaName} is due in 7 days and is not fully complete.",
            'upcoming' => "{$areaName} is due in {$this->daysRemaining} days and is not fully complete.",
            '1_day' => "{$areaName} is due tomorrow and is not fully complete.",
            'deadline_day' => "{$areaName} is due today and is not fully complete.",
            'overdue' => "{$areaName} is past its deadline and is still incomplete.",
            default => "{$areaName} has an upcoming accreditation deadline.",
        };
    }
}
