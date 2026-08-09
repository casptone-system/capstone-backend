<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginVerificationCodeNotification extends Notification
{
    use Queueable;

    private string $code;
    private int $expiresInMinutes;

    public function __construct(string $code, int $expiresInMinutes)
    {
        $this->code = $code;
        $this->expiresInMinutes = $expiresInMinutes;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Your ADAMS Login Verification Code')
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name ?? 'User') . ',')
            ->line('A sign-in attempt was made for your account. Enter the verification code below to complete the login process.')
            ->line('Verification code: **' . $this->code . '**')
            ->line('This code will expire in ' . $this->expiresInMinutes . ' minutes.')
            ->line('If you did not attempt to sign in, please ignore this email or contact your administrator.')
            ->salutation('Thank you,')
            ->line('ADAMS Security Team');
    }
}