<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class FileRequestRules
{
    public const MAX_FILES = 5;

    public const DAILY_LIMIT = 20;

    public const TITLE_MAX = 255;

    public const DESCRIPTION_MAX = 5000;

    public const DECLINE_REASON_MAX = 1000;

    public static function maxKilobytes(): int
    {
        return (int) config('filesystems.document_upload_max_kb', 51200);
    }

    public static function assertPdf(UploadedFile $file): void
    {
        if (! AreaDocumentRules::isPdf($file->getClientOriginalName(), $file->getMimeType())) {
            throw ValidationException::withMessages([
                'files' => 'File request uploads must be PDF.',
            ]);
        }

        $maxBytes = self::maxKilobytes() * 1024;
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'files' => 'Each PDF must be 50 MB or smaller.',
            ]);
        }
    }

    public static function openKey(int $requesterProgramId, int $recipientProgramId, string $title): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));

        return $requesterProgramId.'|'.$recipientProgramId.'|'.$normalized;
    }
}
