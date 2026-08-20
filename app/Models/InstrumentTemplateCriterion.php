<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentTemplateCriterion extends Model
{
    protected $fillable = ['parameter_id', 'title', 'description', 'evidence_type', 'sort_order'];

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(InstrumentTemplateParameter::class, 'parameter_id');
    }
}
