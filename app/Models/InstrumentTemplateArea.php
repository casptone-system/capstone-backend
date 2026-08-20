<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstrumentTemplateArea extends Model
{
    protected $fillable = ['template_id', 'name', 'description', 'sort_order'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(InstrumentTemplate::class, 'template_id');
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(InstrumentTemplateParameter::class, 'area_id')->orderBy('sort_order');
    }
}
