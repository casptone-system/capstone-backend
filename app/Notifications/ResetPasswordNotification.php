<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class ResetPasswordNotification extends ResetPassword
{
    use Queueable;

    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        $frontendUrl = env('FRONTEND_URL', config('app.url'));
        $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset Your Password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in ' . Config::get('auth.passwords.users.expire') . ' minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
