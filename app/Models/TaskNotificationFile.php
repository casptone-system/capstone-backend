<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskNotificationFile extends Model
{
    protected $table = 'task_notification_files';

    protected $fillable = [
        'task_notification_id',
        'document_id',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'file_type',
        'description',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the task notification this file belongs to
     */
    public function taskNotification(): BelongsTo
    {
        return $this->belongsTo(TaskNotification::class, 'task_notification_id');
    }

    /**
     * Get the associated document if any
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * Get all forwards of this file
     */
    public function forwards(): HasMany
    {
        return $this->hasMany(TaskNotificationFileForward::class, 'task_notification_file_id');
    }

    /**
     * Get forwarded to users
     */
    public function forwardedToUsers()
    {
        return $this->belongsToMany(User::class, 'task_notification_file_forwards', 'task_notification_file_id', 'to_user_id')
            ->withTimestamps()
            ->withPivot('from_user_id', 'message', 'forwarded_at');
    }

    /**
     * Get formatted file size
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get file extension
     */
    public function getFileExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }
}
