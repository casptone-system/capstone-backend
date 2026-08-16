<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    /**
     * Task priorities.
     */
    public const PRIORITIES = [
        'Low',
        'Medium',
        'High',
        'Critical',
    ];

    /**
     * Task statuses.
     */
    public const STATUSES = [
        'Not Started',
        'In Progress',
        'Completed',
        'Overdue',
        'Submitted',
        'Returned',
        'Resubmitted',
        'Approved',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'area_id',
        'accreditation_cycle_id',
        'program_id',
        'requirement_id',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'deadline',
        'created_by',
        'assigned_by',
        'instructions',
        'return_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'deadline' => 'date',
            'created_by' => 'integer',
            'assigned_by' => 'integer',
            'accreditation_cycle_id' => 'integer',
            'program_id' => 'integer',
            'requirement_id' => 'integer',
        ];
    }

    /**
     * Get the accreditation area that owns the task.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }

    /**
     * Get the user who created the task.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the assignments for the task.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'task_id');
    }

    /**
     * Get the accreditation cycle this task belongs to.
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationCycle::class, 'accreditation_cycle_id');
    }

    /**
     * Get the program this task belongs to.
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    /**
     * Get the accreditation requirement this task is based on.
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AccreditationRequirement::class, 'requirement_id');
    }

    /**
     * Get the user who assigned this task.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}