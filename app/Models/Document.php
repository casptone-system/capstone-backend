<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    /**
     * Supported file MIME types.
     */
    public const SUPPORTED_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
        'image/jpeg',
        'image/png',
    ];

    /**
     * Supported file extensions for display.
     */
    public const SUPPORTED_EXTENSIONS = [
        'pdf',
        'docx',
        'xlsx',
        'pptx',
        'zip',
        'jpg',
        'jpeg',
        'png',
    ];

    /**
     * Document statuses.
     */
    public const STATUSES = [
        'Active',
        'Draft',
        'Revision Requested',
        'Archived',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'program_id',
        'area_id',
        'task_id',
        'content_row_id',
        'title',
        'description',
        'school_year',
        'uploaded_by',
        'current_version',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    /**
     * Documents attached directly to an area or via a parameter content row.
     */
    public function scopeForArea(Builder $query, int $areaId): Builder
    {
        return $query->where(function (Builder $inner) use ($areaId) {
            $inner->where('area_id', $areaId)
                ->orWhereHas('contentRow.parameter', function (Builder $parameter) use ($areaId) {
                    $parameter->where('area_id', $areaId);
                });
        });
    }

    public function scopePdfOnly(Builder $query): Builder
    {
        return $query->whereHas('versions', function (Builder $versions) {
            $versions->where(function (Builder $inner) {
                $inner->where('mime_type', 'application/pdf')
                    ->orWhere('original_name', 'like', '%.pdf');
            });
        });
    }

    /**
     * Documents that belong to a program even when program_id on the row is missing.
     */
    public function scopeForProgram(Builder $query, int $programId): Builder
    {
        return $query->where(function (Builder $inner) use ($programId) {
            $inner->where('program_id', $programId)
                ->orWhereHas('area.cycle', fn (Builder $cycle) => $cycle->where('program_id', $programId))
                ->orWhereHas(
                    'contentRow.parameter.area.cycle',
                    fn (Builder $cycle) => $cycle->where('program_id', $programId)
                );
        });
    }

    /**
     * Get the program that owns the document.
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    /**
     * Get the accreditation area that owns the document.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(AccreditationArea::class, 'area_id');
    }

    /**
     * Get the task that owns the document.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * Get the parameter content row this document is attached to.
     */
    public function contentRow(): BelongsTo
    {
        return $this->belongsTo(ParameterContentRow::class, 'content_row_id');
    }

    /**
     * Get the user who uploaded the document.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the versions of the document.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    /**
     * Get the latest version of the document.
     */
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'id', 'document_id')
            ->where('version', $this->current_version)
            ->latest();
    }
}