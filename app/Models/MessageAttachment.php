<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $fillable = [
        'message_id',
        'role_storage_file_id',
        'file_name',
        'file_path',
        'file_mime',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * Get the message this attachment belongs to
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the storage file this attachment references (if from personal storage)
     */
    public function storageFile(): BelongsTo
    {
        return $this->belongsTo(RoleStorageFile::class, 'role_storage_file_id');
    }

    /**
     * Get formatted file size (KB, MB, GB)
     */
    public function getFormattedSize(): string
    {
        $size = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        foreach ($units as $unit) {
            if ($size < 1024) {
                return round($size, 2) . ' ' . $unit;
            }
            $size /= 1024;
        }
        
        return round($size, 2) . ' TB';
    }

    /**
     * Get file icon based on mime type
     */
    public function getFileIcon(): string
    {
        $mimeToIcon = [
            'application/pdf' => '📄',
            'application/msword' => '📝',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '📝',
            'application/vnd.ms-excel' => '📊',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '📊',
            'text/plain' => '📄',
            'image/jpeg' => '🖼️',
            'image/png' => '🖼️',
            'image/gif' => '🖼️',
            'video/mp4' => '🎥',
            'video/quicktime' => '🎥',
            'application/zip' => '📦',
        ];

        return $mimeToIcon[$this->file_mime] ?? '📎';
    }
}
