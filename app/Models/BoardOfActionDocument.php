<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BoardOfActionDocument extends Model
{
    protected $fillable = [
        'program_id',
        'title',
        'notes',
        'uploaded_by',
        'current_version',
    ];

    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
            'uploaded_by' => 'integer',
            'program_id' => 'integer',
        ];
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BoardOfActionVersion::class, 'board_of_action_document_id');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(BoardOfActionVersion::class, 'board_of_action_document_id')
            ->ofMany('version', 'max');
    }

    public function displayTitle(): string
    {
        $title = trim((string) $this->title);

        if ($title !== '') {
            return $title;
        }

        return $this->latestVersion?->original_name ?: 'Board of Action';
    }

    public function onFileDate(): ?string
    {
        return $this->created_at?->toDateString();
    }
}
