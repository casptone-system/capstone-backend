<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskNotificationFileForward extends Model
{
    protected $table = 'task_notification_file_forwards';

    protected $fillable = [
        'task_notification_id',
        'task_notification_file_id',
        'from_user_id',
        'to_user_id',
        'message',
        'forwarded_at',
    ];

    protected $casts = [
        'forwarded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the task notification
     */
    public function taskNotification(): BelongsTo
    {
        return $this->belongsTo(TaskNotification::class, 'task_notification_id');
    }

    /**
     * Get the file that was forwarded
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(TaskNotificationFile::class, 'task_notification_file_id');
    }

    /**
     * Get the user who forwarded the file
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * Get the user who received the forward
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
