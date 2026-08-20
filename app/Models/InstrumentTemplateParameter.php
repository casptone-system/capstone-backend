<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstrumentTemplateParameter extends Model
{
    protected $fillable = ['area_id', 'code', 'name', 'sort_order'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(InstrumentTemplateArea::class, 'area_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(InstrumentTemplateCriterion::class, 'parameter_id')->orderBy('sort_order');
    }
}
