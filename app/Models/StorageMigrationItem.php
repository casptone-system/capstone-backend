<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageMigrationItem extends Model
{
    public const DIRECTION_TO_R2 = 'to_r2';

    public const DIRECTION_FROM_R2 = 'from_r2';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COPIED = 'copied';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_SOURCE_DELETED = 'source_deleted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'source_type',
        'source_id',
        'file_path',
        'file_size',
        'direction',
        'status',
        'source_checksum',
        'destination_checksum',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
