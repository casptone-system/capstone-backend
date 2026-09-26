<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParameterRowComment extends Model
{
    public const SOURCE_COMMENT = 'comment';

    public const SOURCE_REVISION_REQUEST = 'revision_request';

    protected $fillable = [
        'content_row_id',
        'author_id',
        'author_role',
        'body',
        'source',
        'document_id',
    ];

    public function contentRow(): BelongsTo
    {
        return $this->belongsTo(ParameterContentRow::class, 'content_row_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
