<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChunkedUpload extends Model
{
    use HasUuids;

    public const PURPOSE_DOCUMENT = 'document';

    public const PURPOSE_ROLE_STORAGE = 'role_storage';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'purpose',
        'original_name',
        'mime_type',
        'extension',
        'total_size',
        'chunk_size',
        'total_chunks',
        'received_chunks',
        'status',
        'checksum',
        'metadata',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'received_chunks' => 'array',
            'metadata' => 'array',
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasChunk(int $index): bool
    {
        return in_array($index, $this->received_chunks ?? [], true);
    }

    public function markChunkReceived(int $index): void
    {
        $chunks = collect($this->received_chunks ?? [])->push($index)->unique()->sort()->values()->all();
        $this->received_chunks = $chunks;
        $this->save();
    }

    public function isComplete(): bool
    {
        return count($this->received_chunks ?? []) === $this->total_chunks;
    }
}
