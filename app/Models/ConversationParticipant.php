<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_at',
        'is_archived',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'is_archived' => 'boolean',
    ];

    /**
     * Get the conversation
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get unread message count
     */
    public function getUnreadCount(): int
    {
        return $this->conversation->getUnreadCountForUser($this->user_id);
    }
}
