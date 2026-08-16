<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationRequirement extends Model
{
    use HasFactory;

    public const STATUSES = ['Not Started', 'In Progress', 'Completed'];

    protected $fillable = [
        'area_id',
        'code',
        'title',
        'description',
        'evidence_required',
        'evidence_guidance',
        'required_evidence_type',
        'status',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }
}
