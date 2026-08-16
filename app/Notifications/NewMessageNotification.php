<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Notifications\Notification;

class NewMessageNotification extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        private Message $message,
        private Conversation $conversation,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_message',
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'sender_name' => $this->message->sender->name,
            'subject' => $this->conversation->subject,
            'preview' => substr($this->message->body, 0, 80),
            'program_name' => $this->conversation->accreditationCycle?->program->name,
        ];
    }
}
