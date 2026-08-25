<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class AreaDocumentRules
{
    public const PDF_MIME = 'application/pdf';

    public static function maxKilobytes(): int
    {
        return (int) config('filesystems.area_document_upload_max_kb', 10240);
    }

    public static function maxFilesPerRow(): int
    {
        return (int) config('filesystems.area_document_max_files_per_row', 5);
    }

    public static function isPdf(?string $fileName, ?string $mimeType = null): bool
    {
        $mime = strtolower((string) $mimeType);
        $extension = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

        return $mime === self::PDF_MIME || $extension === 'pdf';
    }

    public static function assertPdfUpload(UploadedFile $file): void
    {
        if (! self::isPdf($file->getClientOriginalName(), $file->getMimeType())) {
            throw ValidationException::withMessages([
                'file' => 'Area documents must be PDF files only.',
            ]);
        }

        $maxBytes = self::maxKilobytes() * 1024;
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => 'Each PDF must be '.self::maxKilobytes().' KB or smaller (10 MB).',
            ]);
        }
    }

    public static function assertPdfMeta(?string $fileName, ?string $mimeType, ?int $sizeBytes): void
    {
        if (! self::isPdf($fileName, $mimeType)) {
            throw ValidationException::withMessages([
                'file' => 'Area documents must be PDF files only.',
            ]);
        }

        if ($sizeBytes !== null && $sizeBytes > self::maxKilobytes() * 1024) {
            throw ValidationException::withMessages([
                'file' => 'Each PDF must be 10 MB or smaller.',
            ]);
        }
    }

    public static function assertRowHasCapacity(?int $contentRowId, int $incoming = 1): void
    {
        if (! $contentRowId || $incoming < 1) {
            return;
        }

        $current = Document::query()->where('content_row_id', $contentRowId)->count();
        $max = self::maxFilesPerRow();

        if (($current + $incoming) > $max) {
            throw ValidationException::withMessages([
                'file' => "This row already has {$current} file(s). A maximum of {$max} PDFs can be uploaded per content row.",
            ]);
        }
    }
}
