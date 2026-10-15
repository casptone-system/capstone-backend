<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamCodeInviteNotification extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly string $programName,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your ADAMS team code')
            ->greeting('Hello,')
            ->line('You have been invited to join ' . $this->programName . ' in ADAMS.')
            ->line('Sign in, then enter this 6-character team code on the Join Team page:')
            ->line('**' . $this->code . '**')
            ->line('Joining adds you as faculty for that program.');
    }
}
