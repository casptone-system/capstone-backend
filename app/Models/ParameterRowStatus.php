<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParameterRowStatus extends Model
{
    protected $fillable = [
        'content_row_id',
        'is_done',
        'done_by',
        'done_at',
    ];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    public function contentRow(): BelongsTo
    {
        return $this->belongsTo(ParameterContentRow::class, 'content_row_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
