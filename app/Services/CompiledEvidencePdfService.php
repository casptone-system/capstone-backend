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
        $parameter->loadMissing('area');

        return $this->safeName(sprintf(
            'ADAMS-%s-%s-compiled.pdf',
            $parameter->area?->code ?: 'area',
            $parameter->code ?: 'parameter'
        ));
    }

    public function filenameForRow(ParameterContentRow $row): string
    {
        $row->loadMissing('parameter.area');

        return $this->safeName(sprintf(
            'ADAMS-%s-row-%d-compiled.pdf',
            $row->parameter?->area?->code ?: 'area',
            $row->id
        ));
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
            $parts[] = $this->dividerPage($parameter, $entry);
            foreach ($entry['files'] as $file) {
                $parts[] = $this->filePages($parameter, $file);
            }
        }

        $parts[] = $this->creditsPage($parameter);

        return $this->merger->merge($parts, $this->credits($parameter)['footnote']);
    }

    /**
     * @param  Collection<int, ParameterContentRow>  $rows
     * @return list<array{row: ParameterContentRow, files: list<array{document: Document, version: DocumentVersion, label: string}>}>
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
     * @param  list<array{row: ParameterContentRow, files: list<array{document: Document, version: DocumentVersion, label: string}>}>  $entries
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
        $writer->paragraph('Following pages include a row divider, then each uploaded file in order. Non-PDF uploads appear as a print insert or image page.');

        return $writer->output();
    }

    /**
     * @param  array{row: ParameterContentRow, files: list<array{document: Document, version: DocumentVersion, label: string}>}  $entry
     */
    private function dividerPage(AccreditationParameter $parameter, array $entry): string
    {
        $writer = $this->writer($parameter);
        $writer->title('Evidence row');
        $writer->keyValue('Instrument', sprintf('Parameter %s: %s', $parameter->code, $parameter->name));
        $writer->keyValue('Row', $this->excerpt((string) $entry['row']->content, 220));
        $writer->keyValue('Files on this row', (string) count($entry['files']));
        $writer->heading('Attached files');
        foreach ($entry['files'] as $file) {
            $writer->paragraph(sprintf(
                '• %s  ·  v%s  ·  %s',
                $file['label'],
                $file['version']->version ?? 1,
                $file['document']->status ?? 'Active'
            ));
        }

        return $writer->output();
    }

    /**
     * @param  array{document: Document, version: DocumentVersion, label: string}  $file
     */
    private function filePages(AccreditationParameter $parameter, array $file): string
    {
        $version = $file['version'];
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

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? $name;
        $name = trim($name, '-');

        return $name !== '' ? $name : 'ADAMS-compiled-evidence.pdf';
    }
}
