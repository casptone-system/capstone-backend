<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskNotification extends Model
{
    protected $fillable = [
        'assigned_by_id',
        'assigned_to_id',
        'title',
        'description',
        'type',
        'is_welcome_task',
        'invitation_id',
        'status',
        'related_id',
        'related_model',
        'viewed_at',
        'badge_clear_at',
        'badge_clear_hours',
        'files_enabled',
        'file_folder_path',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'badge_clear_at' => 'datetime',
        'is_welcome_task' => 'boolean',
        'files_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user who assigned this task
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    /**
     * Get the user this task is assigned to
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * Get the invitation this task is related to
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    /**
     * Get all files attached to this task
     */
    public function files(): HasMany
    {
        return $this->hasMany(TaskNotificationFile::class, 'task_notification_id');
    }

    /**
     * Get all file forwards from this task
     */
    public function fileForwards(): HasMany
    {
        return $this->hasMany(TaskNotificationFileForward::class, 'task_notification_id');
    }

    /**
     * Mark task as viewed and set badge clear time
     */
    public function markAsViewed(): static
    {
        $this->update([
            'viewed_at' => now(),
            'status' => 'viewed',
            'badge_clear_at' => now()->addHours($this->badge_clear_hours),
        ]);

        return $this;
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(): static
    {
        $this->update([
            'status' => 'completed',
        ]);

        return $this;
    }

    /**
     * Check if badge should still show (not cleared by time)
     */
    public function shouldShowBadge(): bool
    {
        // Show badge if:
        // - Not viewed yet, OR
        // - Viewed but badge_clear_at hasn't passed yet
        if ($this->status === 'pending') {
            return true;
        }

        if ($this->badge_clear_at && now()->isBefore($this->badge_clear_at)) {
            return true;
        }

        return false;
    }

    /**
     * Get active (badge-showing) tasks for a user
     */
    public static function getActiveBadgeCount(User $user): int
    {
        return self::where('assigned_to_id', $user->id)
            ->where(function ($query) {
                $query->where('status', 'pending')
                    ->orWhere(function ($subQuery) {
                        $subQuery->where('status', 'viewed')
                            ->where('badge_clear_at', '>', now());
                    });
            })
            ->count();
    }

    /**
     * Get pending tasks for a user (not viewed)
     */
    public static function getPendingForUser(User $user)
    {
        return self::where('assigned_to_id', $user->id)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get all active notifications for user (pending + recently viewed)
     */
    public static function getActiveForUser(User $user)
    {
        return self::where('assigned_to_id', $user->id)
            ->where(function ($query) {
                $query->where('status', 'pending')
                    ->orWhere(function ($subQuery) {
                        $subQuery->where('status', 'viewed')
                            ->where('badge_clear_at', '>', now());
                    });
            })
            ->orderBy('created_at', 'desc');
    }
}
