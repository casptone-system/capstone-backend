<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardOfActionVersion extends Model
{
    protected $fillable = [
        'board_of_action_document_id',
        'version',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'file_size' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BoardOfActionDocument::class, 'board_of_action_document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
