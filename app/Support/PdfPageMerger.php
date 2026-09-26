<?php

namespace App\Support;

/**
 * Concatenates traditional (non-object-stream) PDF 1.4/1.5 files by remapping
 * objects onto a single page tree. Files that cannot be imported are skipped
 * so the caller can insert a generated placeholder page instead.
 */
class PdfPageMerger
{
    /**
     * @param  list<string>  $pdfs
     */
    public function merge(array $pdfs, ?string $footnote = null): string
    {
        $objects = [];
        $pageIds = [];
        $nextId = 3;

        foreach ($pdfs as $pdf) {
            $imported = $this->import($pdf, $nextId);
            if ($imported === null) {
                continue;
            }

            foreach ($imported['objects'] as $id => $body) {
                $objects[$id] = $body;
            }
            foreach ($imported['pageIds'] as $pageId) {
                $pageIds[] = $pageId;
            }
            $nextId = $imported['nextId'];
        }

        if ($pageIds === []) {
            throw new \RuntimeException('No PDF pages could be compiled.');
        }

        if (is_string($footnote) && trim($footnote) !== '') {
            $this->stampFootnotes($objects, $pageIds, $nextId, trim($footnote));
        }

        return $this->assemble($objects, $pageIds);
    }

    public function canImport(string $pdf): bool
    {
        return $this->import($pdf, 3) !== null;
    }

    /**
     * @return array{objects: array<int, string>, pageIds: list<int>, nextId: int}|null
     */
    private function import(string $pdf, int $nextId): ?array
    {
        if (! str_starts_with(ltrim($pdf), '%PDF')) {
            return null;
        }

        $source = $this->extractObjects($pdf);
        if ($source === []) {
            return null;
        }

        $pageIds = [];
        foreach ($source as $id => $body) {
            if ($this->isType($body, 'Page')) {
                $pageIds[] = $id;
            }
        }

        if ($pageIds === []) {
            return null;
        }

        $map = [];
        foreach ($source as $id => $body) {
            if ($this->isType($body, 'Catalog') || $this->isType($body, 'Pages')) {
                continue;
            }
            $map[$id] = $nextId++;
        }

        $objects = [];
        $newPageIds = [];
        foreach ($source as $id => $body) {
            if (! isset($map[$id])) {
                continue;
            }

            $remapped = $this->remap($body, $map);
            if ($this->isType($body, 'Page')) {
                $remapped = preg_replace('/\/Parent\s+\d+\s+\d+\s+R/', '/Parent 2 0 R', $remapped) ?? $remapped;
                $newPageIds[] = $map[$id];
            }
            $objects[$map[$id]] = $remapped;
        }

        if ($newPageIds === []) {
            return null;
        }

        return [
            'objects' => $objects,
            'pageIds' => $newPageIds,
            'nextId' => $nextId,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractObjects(string $pdf): array
    {
        $objects = [];
        if (! preg_match_all('/(\d+)\s+\d+\s+obj\b/', $pdf, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $length = strlen($pdf);
        foreach ($matches[1] as $index => $idMatch) {
            $id = (int) $idMatch[0];
            $header = $matches[0][$index];
            $cursor = $header[1] + strlen($header[0]);
            $end = $this->findEndobj($pdf, $cursor, $length);
            if ($end === null) {
                continue;
            }

            $objects[$id] = trim(substr($pdf, $cursor, $end - $cursor));
        }

        return $objects;
    }

    private function findEndobj(string $pdf, int $cursor, int $length): ?int
    {
        $stream = preg_match('/stream(?:\r\n|\n|\r)/', $pdf, $streamMatch, PREG_OFFSET_CAPTURE, $cursor);
        $endobj = strpos($pdf, 'endobj', $cursor);

        if ($endobj === false) {
            return null;
        }

        if ($stream && isset($streamMatch[0][1]) && $streamMatch[0][1] < $endobj) {
            $dict = substr($pdf, $cursor, $streamMatch[0][1] - $cursor);
            if (preg_match('/\/Length\s+(\d+)\b/', $dict, $lengthMatch)) {
                $streamStart = $streamMatch[0][1] + strlen($streamMatch[0][0]);
                $afterStream = $streamStart + (int) $lengthMatch[1];
                $endobj = strpos($pdf, 'endobj', min($length, max($afterStream, $cursor)));
                if ($endobj === false) {
                    return null;
                }
            }
        }

        return $endobj;
    }

    /**
     * @param  array<int, int>  $map
     */
    private function remap(string $body, array $map): string
    {
        $streamPos = preg_match('/stream(?:\r\n|\n|\r)/', $body, $match, PREG_OFFSET_CAPTURE)
            ? $match[0][1]
            : strlen($body);

        $dict = substr($body, 0, $streamPos);
        $tail = substr($body, $streamPos);
        $dict = preg_replace_callback('/(\d+)\s+(\d+)\s+R/', function (array $ref) use ($map) {
            $old = (int) $ref[1];
            $new = $map[$old] ?? $old;

            return $new.' '.$ref[2].' R';
        }, $dict) ?? $dict;

        return $dict.$tail;
    }

    private function isType(string $body, string $type): bool
    {
        $pattern = $type === 'Page'
            ? '/\/Type\s*\/Page(?!\s*s)/'
            : '/\/Type\s*\/'.preg_quote($type, '/').'\b/';

        $dict = preg_match('/stream(?:\r\n|\n|\r)/', $body, $match, PREG_OFFSET_CAPTURE)
            ? substr($body, 0, $match[0][1])
            : $body;

        return (bool) preg_match($pattern, $dict);
    }

    /**
     * @param  array<int, string>  $objects
     * @param  list<int>  $pageIds
     */
    private function stampFootnotes(array &$objects, array $pageIds, int &$nextId, string $footnote): void
    {
        $fontId = $nextId++;
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $total = count($pageIds);

        foreach ($pageIds as $index => $pageId) {
            if (! isset($objects[$pageId])) {
                continue;
            }

            [$width] = $this->mediaBox($objects[$pageId]);
            $stream = $this->footnoteStream($footnote, $index + 1, $total, $width);
            $streamId = $nextId++;
            $objects[$streamId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream";
            $objects[$pageId] = $this->attachStamp($objects[$pageId], $streamId, $fontId);
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function mediaBox(string $pageBody): array
    {
        if (preg_match('/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/', $pageBody, $matches)) {
            return [(float) $matches[3], (float) $matches[4]];
        }

        return [595.28, 841.89];
    }

    private function footnoteStream(string $text, int $page, int $total, float $width): string
    {
        $margin = 36.0;
        $lineY = 32.0;
        $right = max($margin + 40, $width - $margin);
        $label = strlen($text) > 118 ? substr($text, 0, 115).'...' : $text;

        return sprintf(
            "q\n0.72 0.75 0.78 RG 0.4 w %.2F %.2F m %.2F %.2F l S\nBT /FtNote 7 Tf %.2F %.2F Td (%s) Tj ET\nBT /FtNote 7 Tf %.2F %.2F Td (%s) Tj ET\nQ\n",
            $margin,
            $lineY,
            $right,
            $lineY,
            $margin,
            $lineY - 11,
            $this->escape($label),
            $margin,
            $lineY - 22,
            $this->escape("ADAMS compiled evidence - Page {$page} of {$total}")
        );
    }

    private function attachStamp(string $pageBody, int $contentId, int $fontId): string
    {
        if (preg_match('/\/Contents\s*\[/', $pageBody)) {
            $pageBody = preg_replace(
                '/\/Contents\s*\[(.*?)\]/s',
                '/Contents [$1 '.$contentId.' 0 R]',
                $pageBody,
                1
            ) ?? $pageBody;
        } elseif (preg_match('/\/Contents\s+\d+\s+\d+\s+R/', $pageBody)) {
            $pageBody = preg_replace(
                '/\/Contents\s+(\d+)\s+(\d+)\s+R/',
                '/Contents [$1 $2 R '.$contentId.' 0 R]',
                $pageBody,
                1
            ) ?? $pageBody;
        } else {
            $pageBody = preg_replace('/>>\s*$/', ' /Contents '.$contentId.' 0 R >>', $pageBody, 1) ?? $pageBody;
        }

        if (preg_match('/\/Font\s*<</', $pageBody)) {
            return preg_replace('/\/Font\s*<</', '/Font << /FtNote '.$fontId.' 0 R ', $pageBody, 1) ?? $pageBody;
        }

        if (preg_match('/\/Resources\s*<</', $pageBody)) {
            return preg_replace(
                '/\/Resources\s*<</',
                '/Resources << /Font << /FtNote '.$fontId.' 0 R >> ',
                $pageBody,
                1
            ) ?? $pageBody;
        }

        return preg_replace(
            '/>>\s*$/',
            ' /Resources << /Font << /FtNote '.$fontId.' 0 R >> >> >>',
            $pageBody,
            1
        ) ?? $pageBody;
    }

    private function escape(string $text): string
    {
        $text = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);

        return preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
    }

    /**
     * @param  array<int, string>  $objects
     * @param  list<int>  $pageIds
     */
    private function assemble(array $objects, array $pageIds): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        $kids = implode(' ', array_map(fn (int $id) => $id.' 0 R', $pageIds));
        $count = count($pageIds);
        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [{$kids}] /Count {$count} >>\nendobj\n";

        ksort($objects);
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $maxId = max(array_merge([2], array_keys($objects)));
        $xrefStart = strlen($pdf);
        $size = $maxId + 1;
        $pdf .= "xref\n0 {$size}\n";
        $pdf .= sprintf("%010d 65535 f \n", 0);
        for ($i = 1; $i <= $maxId; $i++) {
            if (isset($offsets[$i])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $pdf .= sprintf("%010d 65535 f \n", 0);
            }
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF\n";

        return $pdf;
    }
}
