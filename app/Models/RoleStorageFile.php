<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleStorageFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'folder_id',
        'role',
        'name',
        'original_name',
        'mime_type',
        'file_size',
        'file_path',
        'is_favorite',
        'status',
        'deleted_at',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(RoleStorageFolder::class, 'folder_id');
    }
}
