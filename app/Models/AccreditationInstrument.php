<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationInstrument extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'version', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function cycles(): HasMany
    {
        return $this->hasMany(AccreditationCycle::class, 'instrument_id');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(AccreditationArea::class, 'instrument_id');
    }
}
