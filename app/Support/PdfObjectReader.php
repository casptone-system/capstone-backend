<?php

namespace App\Support;

/**
 * Loads a PDF's object graph from its cross-reference table, including
 * compressed cross-reference streams and object streams. Object boundaries
 * come from the xref, so indirect /Length values stay intact.
 */
class PdfObjectReader
{
    /** @var array<int, true> */
    private array $visitedXref = [];

    /**
     * @return array<int, string>
     */
    public function read(string $pdf): array
    {
        if (! preg_match_all('/startxref\s+(\d+)/', $pdf, $matches)) {
            return [];
        }

        $entries = [];
        $pos = (int) $matches[1][count($matches[1]) - 1];
        $seen = [];

        while ($pos > 0 && ! isset($seen[$pos])) {
            $seen[$pos] = true;
            $peek = ltrim(substr($pdf, $pos, 16));
            if (str_starts_with($peek, 'xref')) {
                $pos = $this->parseClassicXref($pdf, $pos, $entries) ?? 0;

                continue;
            }

            $pos = $this->parseXrefStream($pdf, $pos, $entries) ?? 0;
        }

        if ($entries === []) {
            return [];
        }

        $offsets = [];
        foreach ($entries as $id => $entry) {
            if (($entry['kind'] ?? '') === 'offset' && ($entry['offset'] ?? 0) > 0) {
                $offsets[$id] = $entry['offset'];
            }
        }

        asort($offsets);
        $ids = array_keys($offsets);
        $positions = array_values($offsets);
        $objects = [];
        $streams = [];

        foreach ($ids as $index => $id) {
            $start = $positions[$index];
            $end = $positions[$index + 1] ?? strlen($pdf);
            if ($this->objectHasStream($pdf, $start, $end)) {
                $streams[$id] = [$start, $end];

                continue;
            }

            $body = $this->slicePlainObject($pdf, $start, $end);
            if ($body !== null) {
                $objects[$id] = $body;
            }
        }

        foreach ($streams as $id => [$start, $end]) {
            $body = $this->sliceStreamObject($pdf, $start, $end, $objects);
            if ($body !== null) {
                $objects[$id] = $body;
            }
        }

        $this->expandObjectStreams($entries, $objects);

        return $objects;
    }

    /**
     * @param  array<int, array{kind: string, offset?: int, stream?: int, index?: int}>  $entries
     */
    private function parseClassicXref(string $pdf, int $pos, array &$entries): ?int
    {
        if (substr($pdf, $pos, 4) !== 'xref') {
            return null;
        }

        $cursor = $pos + 4;
        $length = strlen($pdf);

        while ($cursor < $length) {
            $cursor = $this->skipSpace($pdf, $cursor);
            if ($cursor >= $length || str_starts_with(substr($pdf, $cursor, 7), 'trailer')) {
                break;
            }

            if (! preg_match('/^(\d+)\s+(\d+)\s*/', substr($pdf, $cursor, 40), $subsection)) {
                break;
            }

            $startId = (int) $subsection[1];
            $count = (int) $subsection[2];
            $cursor += strlen($subsection[0]);

            for ($i = 0; $i < $count; $i++) {
                $line = substr($pdf, $cursor, 20);
                if (! preg_match('/^(\d{10}) (\d{5}) ([nf])/', $line, $entry)) {
                    return null;
                }

                $id = $startId + $i;
                if (! isset($entries[$id])) {
                    $entries[$id] = $entry[3] === 'n'
                        ? ['kind' => 'offset', 'offset' => (int) $entry[1]]
                        : ['kind' => 'free'];
                }

                $cursor += 20;
            }
        }

        $trailerAt = strpos($pdf, 'trailer', $pos);
        if ($trailerAt === false) {
            return null;
        }

        $trailer = substr($pdf, $trailerAt, 800);
        if (preg_match('/\/XRefStm\s+(\d+)/', $trailer, $xrefStm)) {
            $streamPos = (int) $xrefStm[1];
            if (! isset($this->visitedXref[$streamPos])) {
                $this->visitedXref[$streamPos] = true;
                $this->parseXrefStream($pdf, $streamPos, $entries);
            }
        }

        if (preg_match('/\/Prev\s+(\d+)/', $trailer, $previous)) {
            return (int) $previous[1];
        }

        return null;
    }

    /**
     * @param  array<int, array{kind: string, offset?: int, stream?: int, index?: int}>  $entries
     */
    private function parseXrefStream(string $pdf, int $pos, array &$entries): ?int
    {
        $object = $this->readDirectStream($pdf, $pos);
        if ($object === null) {
            return null;
        }

        $dict = $object['dict'];
        $data = $this->inflate($dict, $object['data']);
        if ($data === null) {
            return null;
        }

        $widths = [1, 2, 1];
        if (preg_match('/\/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]/', $dict, $widthMatch)) {
            $widths = [(int) $widthMatch[1], (int) $widthMatch[2], (int) $widthMatch[3]];
        }

        $columns = array_sum($widths);
        $predictor = 1;
        if (preg_match('/\/Predictor\s+(\d+)/', $dict, $predictorMatch)) {
            $predictor = (int) $predictorMatch[1];
        }
        if (preg_match('/\/Columns\s+(\d+)/', $dict, $columnMatch)) {
            $columns = (int) $columnMatch[1];
        }

        if ($predictor >= 10) {
            $data = $this->unpredictPng($data, max(1, $columns));
        }

        $index = [[0, 0]];
        if (preg_match('/\/Size\s+(\d+)/', $dict, $sizeMatch)) {
            $index = [[0, (int) $sizeMatch[1]]];
        }
        if (preg_match('/\/Index\s*\[([^\]]+)\]/', $dict, $indexMatch)) {
            $numbers = array_map('intval', preg_split('/\s+/', trim($indexMatch[1])) ?: []);
            $index = [];
            for ($i = 0; $i + 1 < count($numbers); $i += 2) {
                $index[] = [$numbers[$i], $numbers[$i + 1]];
            }
        }

        $entryWidth = array_sum($widths);
        if ($entryWidth < 1) {
            return null;
        }

        $cursor = 0;
        foreach ($index as [$startId, $count]) {
            for ($i = 0; $i < $count; $i++) {
                if ($cursor + $entryWidth > strlen($data)) {
                    break 2;
                }

                $type = $widths[0] === 0 ? 1 : $this->beInt(substr($data, $cursor, $widths[0]));
                $cursor += $widths[0];
                $field2 = $widths[1] === 0 ? 0 : $this->beInt(substr($data, $cursor, $widths[1]));
                $cursor += $widths[1];
                $field3 = $widths[2] === 0 ? 0 : $this->beInt(substr($data, $cursor, $widths[2]));
                $cursor += $widths[2];

                $id = $startId + $i;
                if (isset($entries[$id])) {
                    continue;
                }

                if ($type === 1) {
                    $entries[$id] = ['kind' => 'offset', 'offset' => $field2];
                } elseif ($type === 2) {
                    $entries[$id] = ['kind' => 'stream', 'stream' => $field2, 'index' => $field3];
                } else {
                    $entries[$id] = ['kind' => 'free'];
                }
            }
        }

        if (preg_match('/\/Prev\s+(\d+)/', $dict, $previous)) {
            return (int) $previous[1];
        }

        return null;
    }

    private function objectHasStream(string $pdf, int $start, int $end): bool
    {
        $chunk = substr($pdf, $start, max(0, $end - $start));
        if (! preg_match('/^\d+\s+\d+\s+obj\b\s*/', $chunk, $header)) {
            return false;
        }

        $head = substr($chunk, strlen($header[0]), 16384);
        $streamAt = $this->keywordOffset($head, 'stream');
        $endobjAt = strpos($head, 'endobj');

        return $streamAt !== false && ($endobjAt === false || $streamAt < $endobjAt);
    }

    private function slicePlainObject(string $pdf, int $start, int $end): ?string
    {
        $chunk = substr($pdf, $start, max(0, $end - $start));
        if (! preg_match('/^\d+\s+\d+\s+obj\b\s*/', $chunk, $header)) {
            return null;
        }

        $bodyStart = strlen($header[0]);
        $endobj = strpos($chunk, 'endobj', $bodyStart);
        if ($endobj === false) {
            return null;
        }

        return trim(substr($chunk, $bodyStart, $endobj - $bodyStart));
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function sliceStreamObject(string $pdf, int $start, int $end, array $objects): ?string
    {
        $chunk = substr($pdf, $start, max(0, $end - $start));
        if (! preg_match('/^\d+\s+\d+\s+obj\b\s*/', $chunk, $header)) {
            return null;
        }

        $bodyStart = strlen($header[0]);
        $streamAt = $this->keywordOffset(substr($chunk, $bodyStart), 'stream');
        if ($streamAt === false) {
            return $this->slicePlainObject($pdf, $start, $end);
        }

        $dict = substr($chunk, $bodyStart, $streamAt);
        $keyword = $this->streamKeyword($chunk, $bodyStart + $streamAt);
        if ($keyword === null) {
            return null;
        }

        $length = $this->streamLength($dict, $objects);
        if ($length === null) {
            $endobj = strrpos($chunk, 'endobj');
            if ($endobj === false || $endobj < $bodyStart) {
                return null;
            }

            return trim(substr($chunk, $bodyStart, $endobj - $bodyStart));
        }

        $dataStart = $bodyStart + $streamAt + strlen($keyword);
        $endobj = strpos($chunk, 'endobj', $dataStart + $length);
        if ($endobj === false) {
            return null;
        }

        return substr($chunk, $bodyStart, $endobj - $bodyStart);
    }

    /**
     * @param  array<int, array{kind: string, offset?: int, stream?: int, index?: int}>  $entries
     * @param  array<int, string>  $objects
     */
    private function expandObjectStreams(array $entries, array &$objects): void
    {
        $decoded = [];

        foreach ($objects as $id => $body) {
            if (! str_contains($this->dictionary($body), '/ObjStm')) {
                continue;
            }

            $parts = $this->storedStream($body, $objects);
            if ($parts === null) {
                continue;
            }

            $data = $this->inflate($parts['dict'], $parts['data']);
            if ($data === null) {
                continue;
            }

            $count = 0;
            $first = 0;
            if (preg_match('/\/N\s+(\d+)/', $parts['dict'], $countMatch)) {
                $count = (int) $countMatch[1];
            }
            if (preg_match('/\/First\s+(\d+)/', $parts['dict'], $firstMatch)) {
                $first = (int) $firstMatch[1];
            }
            if ($count < 1 || $first < 1 || $first > strlen($data)) {
                continue;
            }

            preg_match_all('/\d+/', substr($data, 0, $first), $numbers);
            $pairs = $numbers[0] ?? [];
            $located = [];
            for ($i = 0; $i + 1 < count($pairs) && count($located) < $count; $i += 2) {
                $located[] = [
                    'id' => (int) $pairs[$i],
                    'at' => $first + (int) $pairs[$i + 1],
                ];
            }

            $bodies = [];
            foreach ($located as $index => $item) {
                $next = $located[$index + 1]['at'] ?? strlen($data);
                $bodies[$index] = trim(substr($data, $item['at'], max(0, $next - $item['at'])));
                $bodies['id:'.$item['id']] = $bodies[$index];
            }

            $decoded[$id] = $bodies;
        }

        foreach ($entries as $id => $entry) {
            if (($entry['kind'] ?? '') !== 'stream') {
                continue;
            }

            $streamId = $entry['stream'] ?? null;
            $index = $entry['index'] ?? null;
            if ($streamId === null || $index === null || ! isset($decoded[$streamId][$index])) {
                continue;
            }

            $objects[$id] = $decoded[$streamId][$index];
        }
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function streamLength(string $dict, array $objects): ?int
    {
        if (preg_match('/\/Length\s+(\d+)\s+(\d+)\s+R/', $dict, $indirect)) {
            $body = $objects[(int) $indirect[1]] ?? null;
            if (! is_string($body) || ! preg_match('/^\s*(\d+)\s*$/', $body, $value)) {
                return null;
            }

            return (int) $value[1];
        }

        if (preg_match('/\/Length\s+(\d+)(?!\s+\d)/', $dict, $direct)) {
            return (int) $direct[1];
        }

        return null;
    }

    /**
     * @return array{dict: string, data: string}|null
     */
    private function readDirectStream(string $pdf, int $offset): ?array
    {
        if (! preg_match('/^(\d+)\s+\d+\s+obj\b\s*/', substr($pdf, $offset, 48), $header)) {
            return null;
        }

        $cursor = $offset + strlen($header[0]);
        $head = substr($pdf, $cursor, 8192);
        $streamAt = $this->keywordOffset($head, 'stream');
        if ($streamAt === false) {
            return null;
        }

        $dict = substr($head, 0, $streamAt);
        if (! preg_match('/\/Length\s+(\d+)(?!\s+\d)/', $dict, $lengthMatch)) {
            return null;
        }

        $keyword = $this->streamKeyword($pdf, $cursor + $streamAt);
        if ($keyword === null) {
            return null;
        }

        $dataStart = $cursor + $streamAt + strlen($keyword);

        return [
            'dict' => $dict,
            'data' => substr($pdf, $dataStart, (int) $lengthMatch[1]),
        ];
    }

    /**
     * @param  array<int, string>  $objects
     * @return array{dict: string, data: string}|null
     */
    private function storedStream(string $body, array $objects): ?array
    {
        $streamAt = $this->keywordOffset($body, 'stream');
        if ($streamAt === false) {
            return null;
        }

        $dict = substr($body, 0, $streamAt);
        $keyword = $this->streamKeyword($body, $streamAt);
        $length = $keyword === null ? null : $this->streamLength($dict, $objects);
        if ($keyword === null || $length === null) {
            return null;
        }

        $dataStart = $streamAt + strlen($keyword);

        return [
            'dict' => $dict,
            'data' => substr($body, $dataStart, $length),
        ];
    }

    private function inflate(string $dict, string $data): ?string
    {
        if (! str_contains($dict, '/FlateDecode') && ! str_contains($dict, '/Fl')) {
            return $data;
        }

        $decoded = @gzuncompress($data);
        if ($decoded === false) {
            $decoded = @gzinflate($data);
        }

        return $decoded === false ? null : $decoded;
    }

    private function unpredictPng(string $data, int $columns): string
    {
        $rowWidth = $columns + 1;
        if ($columns < 1 || strlen($data) < $rowWidth || strlen($data) % $rowWidth !== 0) {
            return $data;
        }

        $rows = intdiv(strlen($data), $rowWidth);
        $output = '';
        $previous = array_fill(0, $columns, 0);

        for ($row = 0; $row < $rows; $row++) {
            $offset = $row * $rowWidth;
            $predictor = ord($data[$offset]);
            $current = [];
            for ($column = 0; $column < $columns; $column++) {
                $raw = ord($data[$offset + 1 + $column]);
                $left = $column > 0 ? $current[$column - 1] : 0;
                $up = $previous[$column];
                $upLeft = $column > 0 ? $previous[$column - 1] : 0;
                $current[$column] = ($raw + $this->predicted($predictor, $left, $up, $upLeft)) & 255;
            }

            foreach ($current as $byte) {
                $output .= chr($byte);
            }
            $previous = $current;
        }

        return $output;
    }

    private function predicted(int $predictor, int $left, int $up, int $upLeft): int
    {
        return match ($predictor) {
            1 => $left,
            2 => $up,
            3 => intdiv($left + $up, 2),
            4 => $this->paeth($left, $up, $upLeft),
            default => 0,
        };
    }

    private function paeth(int $left, int $up, int $upLeft): int
    {
        $estimate = $left + $up - $upLeft;
        $leftDistance = abs($estimate - $left);
        $upDistance = abs($estimate - $up);
        $diagonal = abs($estimate - $upLeft);

        if ($leftDistance <= $upDistance && $leftDistance <= $diagonal) {
            return $left;
        }

        return $upDistance <= $diagonal ? $up : $upLeft;
    }

    private function beInt(string $bytes): int
    {
        $value = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    private function dictionary(string $body): string
    {
        $streamAt = $this->keywordOffset($body, 'stream');

        return $streamAt === false ? $body : substr($body, 0, $streamAt);
    }

    private function keywordOffset(string $bytes, string $keyword): int|false
    {
        $offset = 0;
        $length = strlen($bytes);
        $needle = strlen($keyword);

        while ($offset < $length) {
            $found = strpos($bytes, $keyword, $offset);
            if ($found === false) {
                return false;
            }

            $before = $found === 0 ? ' ' : $bytes[$found - 1];
            $after = $bytes[$found + $needle] ?? ' ';
            if (preg_match('/[\s>\/\[<]/', $before) && preg_match('/[\s>\/\[<]/', $after)) {
                return $found;
            }

            $offset = $found + $needle;
        }

        return false;
    }

    private function streamKeyword(string $bytes, int $offset): ?string
    {
        if (substr($bytes, $offset, 8) === "stream\r\n") {
            return "stream\r\n";
        }

        if (substr($bytes, $offset, 7) === "stream\n") {
            return "stream\n";
        }

        if (substr($bytes, $offset, 7) === "stream\r") {
            return "stream\r";
        }

        return null;
    }

    private function skipSpace(string $pdf, int $cursor): int
    {
        $length = strlen($pdf);
        while ($cursor < $length && ctype_space($pdf[$cursor])) {
            $cursor++;
        }

        return $cursor;
    }
}
