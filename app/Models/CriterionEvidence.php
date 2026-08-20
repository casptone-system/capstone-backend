<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriterionEvidence extends Model
{
    protected $table = 'criterion_evidence';

    protected $fillable = [
        'requirement_id',
        'parameter_id',
        'area_id',
        'workspace_id',
        'uploaded_by',
        'role_storage_file_id',
        'evidence_type',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
        'is_done',
        'marked_done_at',
    ];

    protected $casts = [
        'is_done' => 'boolean',
        'marked_done_at' => 'datetime',
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AccreditationRequirement::class, 'requirement_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(AccreditationParameter::class, 'parameter_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
