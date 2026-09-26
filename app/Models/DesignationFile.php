<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignationFile extends Model
{
    public const ROLE_CHAIR = 'chair';

    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'user_id',
        'area_id',
        'designated_by',
        'role',
        'role_label',
        'area_label',
        'program_name',
        'level',
        'place_name',
        'signer_name',
        'designation_date',
        'valid_until',
        'file_path',
        'original_name',
    ];

    protected function casts(): array
    {
        return [
            'designation_date' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }

    public function designatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designated_by');
    }

}
