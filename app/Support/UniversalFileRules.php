<?php

namespace App\Support;

use App\Models\AccreditationArea;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UniversalFileRules
{
    public const MAX_FILES = 10;

    public const GENERAL = 'general';

    /**
     * Stable categories. General covers files that every area can use.
     * The other codes match the fixed AACCUP areas, not a single program's area row.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function areas(): array
    {
        return array_merge(
            [['code' => self::GENERAL, 'name' => 'General — all areas']],
            AccreditationArea::AACCUP_AREAS,
        );
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::areas(), 'code');
    }

    public static function nameFor(?string $code): string
    {
        foreach (self::areas() as $area) {
            if ($area['code'] === $code) {
                return $area['name'];
            }
        }

        return 'General — all areas';
    }

    public static function maxKilobytes(): int
    {
        return (int) config('filesystems.document_upload_max_kb', 51200);
    }

    public static function assertPdf(UploadedFile $file): void
    {
        if (! AreaDocumentRules::isPdf($file->getClientOriginalName(), $file->getMimeType())) {
            throw ValidationException::withMessages([
                'files' => 'Universal files must be PDF.',
            ]);
        }

        $maxBytes = self::maxKilobytes() * 1024;
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'files' => 'Each PDF must be 50 MB or smaller.',
            ]);
        }
    }
}
