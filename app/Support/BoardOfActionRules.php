<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class BoardOfActionRules
{
    public static function maxKilobytes(): int
    {
        return (int) config('filesystems.document_upload_max_kb', 51200);
    }

    public static function assertPdf(UploadedFile $file): void
    {
        if (! AreaDocumentRules::isPdf($file->getClientOriginalName(), $file->getMimeType())) {
            throw ValidationException::withMessages([
                'file' => 'Board of Action files must be PDF.',
            ]);
        }

        $maxBytes = self::maxKilobytes() * 1024;
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => 'Each PDF must be 50 MB or smaller.',
            ]);
        }
    }
}
