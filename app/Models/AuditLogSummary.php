<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLogSummary extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'event',
        'total_count',
        'last_occurred_at',
    ];

    protected $casts = [
        'last_occurred_at' => 'datetime',
    ];
}
