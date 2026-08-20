<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoleStorageFolder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'program_id',
        'parent_id',
        'workspace_id',
        'area_id',
        'parameter_id',
        'role',
        'name',
        'folder_kind',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RoleStorageFile::class, 'folder_id');
    }
}
