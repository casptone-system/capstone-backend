<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Validation\ValidationException;

class EvidenceLinkRules
{
    public const MAX_URL_LENGTH = 2048;

    public const MAX_TITLE_LENGTH = 255;

    /**
     * Accept any http or https URL. Do not allowlist hosts, and do not fetch the URL.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw ValidationException::withMessages([
                'url' => 'Enter a link URL.',
            ]);
        }

        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw ValidationException::withMessages([
                'url' => 'That link is too long. Use a URL of '.self::MAX_URL_LENGTH.' characters or fewer.',
            ]);
        }

        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw ValidationException::withMessages([
                'url' => 'Enter a valid http or https URL.',
            ]);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) (is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string) (is_array($parts) ? ($parts['host'] ?? '') : '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw ValidationException::withMessages([
                'url' => 'Only http and https links are allowed.',
            ]);
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages([
                'url' => 'Enter a valid http or https URL.',
            ]);
        }

        return $url;
    }

    public static function title(?string $title, string $url): string
    {
        $title = trim((string) $title);

        if ($title === '') {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : $url;
        }

        if (strlen($title) > self::MAX_TITLE_LENGTH) {
            throw ValidationException::withMessages([
                'title' => 'The link title must be '.self::MAX_TITLE_LENGTH.' characters or fewer.',
            ]);
        }

        return $title;
    }

    public static function assertNotDuplicate(int $contentRowId, string $url): void
    {
        $exists = Document::query()
            ->where('content_row_id', $contentRowId)
            ->where('kind', Document::KIND_LINK)
            ->where('url', $url)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'url' => 'This link is already attached to this row.',
            ]);
        }
    }
}
