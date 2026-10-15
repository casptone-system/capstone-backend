<?php

namespace App\Services;

use App\Models\AccreditationParameter;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ParameterContentRow;
use App\Models\User;
use App\Support\PdfPageMerger;
use App\Support\SimplePdfWriter;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Evidence links (documents.kind = link) cannot be fetched or printed as file
 * pages. Each link is listed on its own placeholder page with its title and
 * full URL. Do not omit link rows from this packet.
 */
class CompiledEvidencePdfService
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly PdfPageMerger $merger,
    ) {}

    public function compileParameter(AccreditationParameter $parameter): string
    {
        $parameter->loadMissing([
            'area.chair',
            'area.members.user',
            'area.cycle.program.college',
            'contentRows.documents.versions',
        ]);

        $rows = $parameter->contentRows
            ->filter(fn (ParameterContentRow $row) => ! $row->isSectionHeading())
            ->values();

        return $this->compile($parameter, $rows, 'instrument');
    }

    public function compileRow(ParameterContentRow $row): string
    {
        $row->loadMissing([
            'parameter.area.chair',
            'parameter.area.members.user',
            'parameter.area.cycle.program.college',
            'documents.versions',
        ]);

        if ($row->isSectionHeading()) {
            throw new RuntimeException('Section headings do not have compiled evidence.');
        }

        return $this->compile($row->parameter, collect([$row]), 'row');
    }

    public function filenameForParameter(AccreditationParameter $parameter): string
    {
        $parameter->loadMissing(['area.cycle.program', 'contentRows.documents']);
        $area = $parameter->area;

        return $this->packetFilename([
            $area?->cycle?->program?->code,
            $area?->sidebarLabel(),
            trim('Parameter '.(string) $parameter->code),
            $parameter->name,
        ], $this->includedRowLabel($parameter->contentRows));
    }

    public function filenameForRow(ParameterContentRow $row): string
    {
        $row->loadMissing('parameter.area.cycle.program');
        $parameter = $row->parameter;
        $area = $parameter?->area;

        return $this->packetFilename([
            $area?->cycle?->program?->code,
            $area?->sidebarLabel(),
            trim('Parameter '.(string) $parameter?->code),
            $parameter?->name,
        ], (string) $row->content);
    }

    /**
     * @param  Collection<int, ParameterContentRow>  $rows
     */
    private function compile(AccreditationParameter $parameter, Collection $rows, string $scope): string
    {
        $entries = $this->entries($rows);

        if ($entries === []) {
            throw new RuntimeException(
                $scope === 'row'
                    ? 'Upload at least one file on this row before downloading a compiled PDF.'
                    : 'Upload at least one file on this instrument before downloading a compiled PDF.'
            );
        }

        $parts = [];
        $parts[] = $this->coverAndIndex($parameter, $entries, $scope);

        foreach ($entries as $entry) {
            foreach ($entry['files'] as $file) {
                $parts[] = $this->filePages($parameter, $file);
            }
        }

        $parts[] = $this->creditsPage($parameter);

        return $this->merger->merge($parts, $this->credits($parameter)['footnote']);
    }

    /**
     * @param  Collection<int, ParameterContentRow>  $rows
     * @return list<array{row: ParameterContentRow, files: list<array{document: Document, version: ?DocumentVersion, label: string}>}>
     */
    private function entries(Collection $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $files = [];
            foreach ($row->documents as $document) {
                if ($document->status === 'Archived') {
                    continue;
                }

                if ($document->isLink()) {
                    $files[] = [
                        'document' => $document,
                        'version' => null,
                        'label' => $document->title ?: 'Evidence link',
                    ];

                    continue;
                }

                $version = $document->versions->sortByDesc('version')->first();
                if (! $version) {
                    continue;
                }

                $files[] = [
                    'document' => $document,
                    'version' => $version,
                    'label' => $version->original_name ?: $document->title ?: 'Uploaded file',
                ];
            }

            if ($files === []) {
                continue;
            }

            $entries[] = [
                'row' => $row,
                'files' => $files,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{row: ParameterContentRow, files: list<array{document: Document, version: ?DocumentVersion, label: string}>}>  $entries
     */
    private function coverAndIndex(AccreditationParameter $parameter, array $entries, string $scope): string
    {
        $area = $parameter->area;
        $cycle = $area?->cycle;
        $program = $cycle?->program;
        $college = $program?->college ?? $cycle?->college;
        $fileCount = array_sum(array_map(fn (array $entry) => count($entry['files']), $entries));

        $credits = $this->credits($parameter);
        $writer = $this->writer($parameter);
        $writer->title('Compiled Evidence Packet');
        $writer->subtitle('Ready to print  ·  Generated '.now()->timezone(config('app.timezone'))->format('F j, Y g:i A'));
        $writer->heading('Accreditation context');
        $writer->keyValue('College', (string) ($college?->name ?? 'N/A'));
        $writer->keyValue('Program', (string) ($program?->name ?? $program?->code ?? 'N/A'));
        $writer->keyValue('Cycle / level', trim((string) ($cycle?->level ?? '').' '.($cycle?->status ?? '')) ?: 'N/A');
        $writer->keyValue('Area', (string) ($area?->sidebarLabel() ?? $area?->name ?? 'N/A'));
        $writer->keyValue('Instrument', sprintf('Parameter %s: %s', $parameter->code, $parameter->name));
        $writer->keyValue('Scope', $scope === 'row' ? 'Single content row' : 'Entire instrument');
        $writer->keyValue('Rows with files', (string) count($entries));
        $writer->keyValue('Files included', (string) $fileCount);
        $writer->keyValue('Area Chair', $credits['chair']);
        $writer->keyValue('Area Members', $credits['members']);

        $writer->heading('Contents');
        $indexRows = [];
        foreach ($entries as $index => $entry) {
            foreach ($entry['files'] as $file) {
                $indexRows[] = [
                    (string) ($index + 1),
                    $this->excerpt((string) $entry['row']->content, 48),
                    $file['label'],
                    (string) ($file['document']->status ?? ''),
                ];
            }
        }
        $writer->table(['#', 'Row', 'File', 'Status'], $indexRows);
        $writer->paragraph('Following pages include each uploaded file in order. Evidence links are listed on a placeholder page with the title and full URL. Non-PDF uploads appear as a print insert or image page.');

        return $writer->output();
    }

    /**
     * @param  array{document: Document, version: ?DocumentVersion, label: string}  $file
     */
    private function filePages(AccreditationParameter $parameter, array $file): string
    {
        if ($file['document']->isLink()) {
            return $this->linkPlaceholder($parameter, $file['label'], (string) $file['document']->url);
        }

        $version = $file['version'];
        if (! $version) {
            return $this->placeholder($parameter, $file['label'], 'The uploaded file could not be read from storage.');
        }

        $contents = $this->storage->getContents((string) $version->file_path);
        $caption = sprintf('%s  ·  %s', $file['label'], $file['document']->status ?? '');

        if ($contents === null) {
            return $this->placeholder($parameter, $caption, 'The uploaded file could not be read from storage.');
        }

        if ($this->isPdf($version)) {
            if ($this->merger->canImport($contents)) {
                return $contents;
            }

            return $this->placeholder($parameter, $caption, 'This PDF uses a format that cannot be merged automatically. Download the original file from the row to print it separately.');
        }

        $jpeg = $this->asJpeg($contents, $version);
        if ($jpeg !== null) {
            $writer = $this->writer($parameter);
            $writer->jpegPage($jpeg['bytes'], $jpeg['width'], $jpeg['height'], $caption);

            return $writer->output();
        }

        return $this->placeholder(
            $parameter,
            $caption,
            'This file type is included in the packet as a print insert. Open the original upload from the row if you need the native file.'
        );
    }

    /**
     * Links are never fetched. The placeholder page is the printed stand-in.
     */
    private function linkPlaceholder(AccreditationParameter $parameter, string $title, string $url): string
    {
        $writer = $this->writer($parameter);
        $writer->title('Evidence link');
        $writer->heading($title !== '' ? $title : 'Evidence link');
        $writer->paragraph('This evidence is a link and cannot be printed into the PDF. It is listed here so the packet does not omit it.');
        $writer->paragraph($url !== '' ? $url : 'No URL was stored for this link.');

        return $writer->output();
    }

    private function placeholder(AccreditationParameter $parameter, string $title, string $message): string
    {
        $writer = $this->writer($parameter);
        $writer->title('Evidence insert');
        $writer->heading($title);
        $writer->paragraph($message);

        return $writer->output();
    }

    private function creditsPage(AccreditationParameter $parameter): string
    {
        $credits = $this->credits($parameter);
        $writer = $this->writer($parameter);
        $writer->title('Credits');
        $writer->paragraph('This compiled evidence packet is prepared by the assigned area team.');
        $writer->keyValue('Area Chair', $credits['chair']);
        $writer->keyValue('Area Members', $credits['members']);
        $writer->paragraph($credits['footnote']);

        return $writer->output();
    }

    private function writer(AccreditationParameter $parameter): SimplePdfWriter
    {
        return new SimplePdfWriter;
    }

    /**
     * @return array{chair: string, members: string, footnote: string}
     */
    private function credits(AccreditationParameter $parameter): array
    {
        $parameter->loadMissing(['area.chair', 'area.members.user']);
        $area = $parameter->area;
        $chair = $area?->chair;
        $chairName = $this->personName($chair);
        $memberNames = collect($area?->members)
            ->map(fn ($member) => $this->personName($member->user))
            ->filter(fn (string $name) => $name !== '' && $name !== $chairName)
            ->unique()
            ->values();

        $parts = [];
        if ($chairName !== '') {
            $parts[] = $chairName.', Area Chair';
        }
        if ($memberNames->isNotEmpty()) {
            $label = $memberNames->count() === 1 ? 'Area Member' : 'Area Members';
            $parts[] = $label.' '.$memberNames->implode(', ');
        }

        return [
            'chair' => $chairName !== '' ? $chairName : 'Not assigned',
            'members' => $memberNames->isNotEmpty() ? $memberNames->implode(', ') : 'None assigned',
            'footnote' => $parts === []
                ? 'Prepared by the assigned area team'
                : 'Prepared by '.$this->excerpt(implode(' | ', $parts), 160),
        ];
    }

    private function personName(?User $user): string
    {
        if (! $user) {
            return '';
        }

        $full = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ]))) ?? '');

        if ($full !== '') {
            return $full;
        }

        return trim((string) ($user->name ?: $user->email));
    }

    private function isPdf(DocumentVersion $version): bool
    {
        $mime = strtolower((string) $version->mime_type);
        $name = strtolower((string) $version->original_name);

        return $mime === 'application/pdf' || str_ends_with($name, '.pdf');
    }

    /**
     * @return array{bytes: string, width: int, height: int}|null
     */
    private function asJpeg(string $contents, DocumentVersion $version): ?array
    {
        $mime = strtolower((string) $version->mime_type);
        $ext = strtolower((string) pathinfo((string) $version->original_name, PATHINFO_EXTENSION));

        if (in_array($mime, ['image/jpeg', 'image/jpg'], true) || in_array($ext, ['jpg', 'jpeg'], true)) {
            $size = $this->jpegSize($contents);
            if ($size) {
                return ['bytes' => $contents, 'width' => $size[0], 'height' => $size[1]];
            }
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        if (! (str_starts_with($mime, 'image/') || in_array($ext, ['png', 'gif', 'webp', 'jpg', 'jpeg'], true))) {
            return null;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes !== '' ? ['bytes' => $bytes, 'width' => $width, 'height' => $height] : null;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function jpegSize(string $jpeg): ?array
    {
        $length = strlen($jpeg);
        $i = 2;
        while ($i + 8 < $length) {
            if (ord($jpeg[$i]) !== 0xFF) {
                $i++;

                continue;
            }

            $marker = ord($jpeg[$i + 1]);
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $i += 2;

                continue;
            }

            $segment = (ord($jpeg[$i + 2]) << 8) + ord($jpeg[$i + 3]);
            if ($marker >= 0xC0 && $marker <= 0xC3 && $i + 8 < $length) {
                $height = (ord($jpeg[$i + 5]) << 8) + ord($jpeg[$i + 6]);
                $width = (ord($jpeg[$i + 7]) << 8) + ord($jpeg[$i + 8]);

                return [$width, $height];
            }

            $i += 2 + $segment;
        }

        return null;
    }

    private function excerpt(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return strlen($text) > $max ? substr($text, 0, $max - 3).'...' : $text;
    }

    /**
     * @param  Collection<int, ParameterContentRow>  $rows
     */
    private function includedRowLabel(Collection $rows): string
    {
        $names = $rows
            ->filter(function (ParameterContentRow $row) {
                if ($row->isSectionHeading()) {
                    return false;
                }

                if (! $row->relationLoaded('documents')) {
                    return trim((string) $row->content) !== '';
                }

                return $row->documents->contains(
                    fn (Document $document) => $document->status !== 'Archived'
                );
            })
            ->map(fn (ParameterContentRow $row) => trim(preg_replace('/\s+/', ' ', (string) $row->content) ?? ''))
            ->filter()
            ->unique()
            ->values();

        return $this->excerpt($names->implode(', '), 80);
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function packetFilename(array $parts, ?string $rowName = null): string
    {
        $pieces = [];
        foreach ($parts as $part) {
            $stem = $this->filenameStem((string) $part);
            if ($stem !== '') {
                $pieces[] = $stem;
            }
        }

        $rowStem = $this->filenameStem($this->excerpt((string) $rowName, 80));
        $max = 170;

        while ($pieces !== [] && strlen($this->joinFilename($pieces, $rowStem)) > $max) {
            if (count($pieces) > 1) {
                array_shift($pieces);

                continue;
            }

            $room = max(12, $max - ($rowStem !== '' ? strlen($rowStem) + 1 : 0));
            $pieces[0] = rtrim(substr($pieces[0], 0, $room), '-.');
            break;
        }

        $stem = $this->joinFilename($pieces, $rowStem);
        if (strlen($stem) > $max) {
            $stem = rtrim(substr($stem, 0, $max), '-.');
        }

        return ($stem !== '' ? $stem : 'packet').'-compiled-evidence.pdf';
    }

    /**
     * @param  list<string>  $pieces
     */
    private function joinFilename(array $pieces, string $rowStem): string
    {
        $pieces = array_values(array_filter($pieces, fn (string $piece) => $piece !== ''));
        if ($rowStem !== '') {
            $pieces[] = $rowStem;
        }

        return trim(implode('-', $pieces), '-.');
    }

    private function filenameStem(string $value): string
    {
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
        $stem = preg_replace('/-+/', '-', $stem) ?? $stem;

        return trim($stem, '-.');
    }
}
