<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationParameter extends Model
{
    protected $fillable = ['area_id', 'code', 'name', 'sort_order'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(AccreditationRequirement::class, 'parameter_id')->orderBy('code');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(CriterionEvidence::class, 'parameter_id');
    }

    public function contentRows(): HasMany
    {
        return $this->hasMany(ParameterContentRow::class, 'parameter_id')->orderBy('sort_order')->orderBy('id');
    }
}
