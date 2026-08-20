<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'accreditation_cycle_id',
        'area_id',
        'parameter_id',
        'workspace_id',
        'subject',
        'type',
        'created_by',
    ];

    /**
     * Get the accreditation cycle this conversation belongs to
     */
    public function accreditationCycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationCycle::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(AccreditationParameter::class, 'parameter_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(AccreditationWorkspace::class, 'workspace_id');
    }

    /**
     * Get the user who created this conversation
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all messages in this conversation
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get all participants in this conversation
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at', 'is_archived')
            ->withTimestamps();
    }

    /**
     * Get unread message count for a specific user
     */
    public function getUnreadCountForUser($userId): int
    {
        $participant = $this->participants()->where('user_id', $userId)->first();
        
        if (!$participant) {
            return 0;
        }

        $lastReadAt = $participant->pivot->last_read_at;

        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->when($lastReadAt, fn($q) => $q->where('created_at', '>', $lastReadAt))
            ->count();
    }

    /**
     * Mark conversation as read for a user
     */
    public function markAsReadForUser($userId): void
    {
        $this->participants()
            ->where('user_id', $userId)
            ->update(['last_read_at' => now()]);
    }

    /**
     * Get latest message
     */
    public function getLatestMessage()
    {
        return $this->messages()->latest('created_at')->first();
    }

    /**
     * Get message preview (first 80 chars of latest message)
     */
    public function getMessagePreview(): string
    {
        $latest = $this->getLatestMessage();
        if (!$latest) {
            return '';
        }
        return substr($latest->body, 0, 80) . (strlen($latest->body) > 80 ? '...' : '');
    }
}
