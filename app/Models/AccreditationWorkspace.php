<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccreditationWorkspace extends Model
{
    protected $fillable = [
        'program_id',
        'cycle_id',
        'template_id',
        'created_by',
        'root_folder_id',
        'name',
        'level',
        'accreditation_date',
        'deadline',
        'phase',
        'status',
        'template_version',
    ];

    protected $casts = [
        'accreditation_date' => 'date',
        'deadline' => 'date',
        'template_version' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationCycle::class, 'cycle_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InstrumentTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rootFolder(): BelongsTo
    {
        return $this->belongsTo(RoleStorageFolder::class, 'root_folder_id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(RoleStorageFolder::class, 'workspace_id');
    }
}
