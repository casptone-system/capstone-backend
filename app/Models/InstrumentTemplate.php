<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstrumentTemplate extends Model
{
    protected $fillable = [
        'name',
        'level',
        'description',
        'is_active',
        'updated_by',
        'version',
        'status',
        'parent_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function areas(): HasMany
    {
        return $this->hasMany(InstrumentTemplateArea::class, 'template_id')->orderBy('sort_order');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(AccreditationWorkspace::class, 'template_id');
    }

    public function isInUse(): bool
    {
        return $this->workspaces()->exists();
    }

    public static function publishedForLevel(string $level): ?self
    {
        return static::with('areas.parameters.criteria')
            ->where('level', $level)
            ->where('status', 'published')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
    }
}
