<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    use HasFactory;

    /**
     * Review workflow statuses.
     */
    public const STATUSES = [
        'Draft',
        'Submitted',
        'Area Approved',
        'Dean Approved',
        'QA Approved',
        'VPAA Approved',
        'Revision Requested',
        'Rejected',
        'Ready',
    ];

    /**
     * Workflow roles in order.
     */
    public const WORKFLOW_ROLES = [
        'Member',
        'Area Chair',
        'Dean',
        'QA',
        'VPAA',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'area_id',
        'cycle_id',
        'current_status',
        'submitted_by',
        'submitted_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'submitted_by' => 'integer',
        ];
    }

    /**
     * Get the accreditation area being reviewed.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }

    /**
     * Get the accreditation cycle this review belongs to.
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AccreditationCycle::class, 'cycle_id');
    }

    /**
     * Get the user who submitted the review.
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Get the comments for the review.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class, 'review_id');
    }

    /**
     * Get the expected reviewer role based on the current status.
     */
    public function getExpectedReviewerRole(): ?string
    {
        return match ($this->current_status) {
            'Submitted' => 'Area Chair',
            'Area Approved' => 'Dean',
            default => null,
        };
    }

    /**
     * Determine if the workflow is at a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->current_status, ['Ready', 'Rejected']);
    }
}