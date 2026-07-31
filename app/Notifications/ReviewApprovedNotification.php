<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewApprovedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Review $review,
        public ?string $approvedBy = null,
        public ?string $approvedByRole = null,
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
        $approverName = $this->approvedBy ?? 'A reviewer';
        $role = $this->approvedByRole ?? 'reviewer';

        return (new MailMessage)
            ->subject('Review Approved: ' . ($this->review->area?->name ?? 'Accreditation Area'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your review submission has been approved by ' . $approverName . ' (' . $role . ').')
            ->line('**Area:** ' . ($this->review->area?->name ?? 'N/A'))
            ->line('**New Status:** ' . $this->review->current_status)
            ->when(
                $this->review->getExpectedReviewerRole(),
                fn ($mail) => $mail->line('**Next Reviewer:** ' . $this->review->getExpectedReviewerRole())
            )
            ->action('View Review', url('/reviews/' . $this->review->id))
            ->line('Your submission is progressing through the review workflow.');
    }

    /**
     * Get the array representation of the notification (stored in database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_approved',
            'title' => 'Review Approved',
            'message' => 'Your review has been approved: ' . ($this->review->area?->name ?? 'Accreditation Area'),
            'review_id' => $this->review->id,
            'current_status' => $this->review->current_status,
            'approved_by' => $this->approvedBy,
            'approved_by_role' => $this->approvedByRole,
            'next_reviewer_role' => $this->review->getExpectedReviewerRole(),
            'area_id' => $this->review->area_id,
            'cycle_id' => $this->review->cycle_id,
        ];
    }
}