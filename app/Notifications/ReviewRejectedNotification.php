<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewRejectedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Review $review,
        public ?string $rejectedBy = null,
        public ?string $rejectedByRole = null,
        public ?string $comment = null,
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
        $rejecterName = $this->rejectedBy ?? 'A reviewer';
        $role = $this->rejectedByRole ?? 'reviewer';

        $mail = (new MailMessage)
            ->subject('Review Rejected: ' . ($this->review->area?->name ?? 'Accreditation Area'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your review submission has been rejected by ' . $rejecterName . ' (' . $role . ').')
            ->line('**Area:** ' . ($this->review->area?->name ?? 'N/A'))
            ->line('**Status:** ' . $this->review->current_status);

        if ($this->comment) {
            $mail->line('**Reviewer Comment:** ' . $this->comment);
        }

        $mail->action('View Review', url('/reviews/' . $this->review->id))
            ->line('Please review the feedback and make necessary adjustments before resubmitting.');

        return $mail;
    }

    /**
     * Get the array representation of the notification (stored in database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_rejected',
            'title' => 'Review Rejected',
            'message' => 'Your review has been rejected: ' . ($this->review->area?->name ?? 'Accreditation Area'),
            'review_id' => $this->review->id,
            'current_status' => $this->review->current_status,
            'rejected_by' => $this->rejectedBy,
            'rejected_by_role' => $this->rejectedByRole,
            'comment' => $this->comment,
            'area_id' => $this->review->area_id,
            'cycle_id' => $this->review->cycle_id,
        ];
    }
}