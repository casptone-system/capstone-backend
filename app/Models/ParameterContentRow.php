<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ParameterContentRow extends Model
{
    protected $fillable = [
        'parameter_id',
        'content',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(AccreditationParameter::class, 'parameter_id');
    }

    public function status(): HasOne
    {
        return $this->hasOne(ParameterRowStatus::class, 'content_row_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'content_row_id');
    }

    public function latestDocument(): HasOne
    {
        return $this->hasOne(Document::class, 'content_row_id')->latestOfMany();
    }

    public function isSectionHeading(): bool
    {
        $text = strtoupper(trim((string) $this->content));
        $text = str_replace(['–', '—', '−'], '-', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = (string) preg_replace('/\s*-\s*/', '-', $text);

        return in_array($text, [
            'IMPLEMENTATION',
            'OUTCOME/S',
            'OUTCOMES',
            'SYSTEM-INPUTS AND PROCESSES',
        ], true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
