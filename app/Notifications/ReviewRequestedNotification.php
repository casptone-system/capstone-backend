<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewRequestedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Review $review,
        public ?string $requestedBy = null,
        public ?string $reviewerRole = null,
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
        $submitterName = $this->requestedBy ?? ($this->review->submitter?->name ?? 'A member');
        $role = $this->reviewerRole ?? $this->review->getExpectedReviewerRole() ?? 'reviewer';

        return (new MailMessage)
            ->subject('Review Requested: ' . $this->review->area?->name ?? 'Accreditation Area')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A review has been submitted by ' . $submitterName . ' and requires your attention.')
            ->line('**Area:** ' . ($this->review->area?->name ?? 'N/A'))
            ->line('**Current Status:** ' . $this->review->current_status)
            ->line('**Your Role:** ' . $role)
            ->when($this->review->submitted_at, fn ($mail) => $mail->line('**Submitted At:** ' . $this->review->submitted_at->format('F j, Y g:i A')))
            ->action('Review Submission', url('/reviews/' . $this->review->id))
            ->line('Please review the submission and take appropriate action (approve, request revision, or reject).');
    }

    /**
     * Get the array representation of the notification (stored in database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_requested',
            'title' => 'Review Requested',
            'message' => 'A review requires your attention: ' . ($this->review->area?->name ?? 'Accreditation Area'),
            'review_id' => $this->review->id,
            'current_status' => $this->review->current_status,
            'reviewer_role' => $this->reviewerRole ?? $this->review->getExpectedReviewerRole(),
            'requested_by' => $this->requestedBy ?? $this->review->submitter?->name,
            'area_id' => $this->review->area_id,
            'cycle_id' => $this->review->cycle_id,
            'submitted_at' => $this->review->submitted_at?->toDateTimeString(),
        ];
    }
}