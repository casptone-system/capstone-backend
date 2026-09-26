<?php

namespace App\Support;

/**
 * Minimal A4 PDF 1.4 writer for print-ready cover, index, and insert pages.
 * Matches the existing PdfExportService output shape so pages can be merged.
 */
class SimplePdfWriter
{
    private const PAGE_WIDTH = 595.28;

    private const PAGE_HEIGHT = 841.89;

    private const MARGIN_LEFT = 48;

    private const MARGIN_RIGHT = 48;

    private const MARGIN_TOP = 52;

    private const MARGIN_BOTTOM = 64;

    private const LINE_HEIGHT = 15;

    /**
     * @var array<int, array{content: string, images: list<array{name: string, bytes: string, width: int, height: int}>}>
     */
    private array $pages = [];

    private int $pageNumber = 0;

    private float $currentY = 0;

    private string $footnote = '';

    public function setFootnote(string $text): self
    {
        $this->footnote = trim($text);

        return $this;
    }

    public function startPage(): void
    {
        if ($this->pageNumber > 0) {
            $this->writeFooter();
        }

        $this->pageNumber++;
        $this->currentY = self::PAGE_HEIGHT - self::MARGIN_TOP;
        $this->pages[$this->pageNumber] = [
            'content' => '',
            'images' => [],
        ];
    }

    public function title(string $text): void
    {
        $this->ensurePage();
        $this->currentY -= 22;
        $this->writeText($text, 18, true);
        $this->currentY -= 8;
    }

    public function subtitle(string $text): void
    {
        $this->ensurePage();
        $this->currentY -= 13;
        $this->writeText($text, 10, false);
        $this->currentY -= 6;
    }

    public function heading(string $text): void
    {
        $this->ensurePage();
        $this->need(28);
        $this->currentY -= 20;
        $this->writeText($text, 13, true);
        $this->currentY -= 6;
    }

    public function keyValue(string $key, string $value): void
    {
        $this->ensurePage();
        $this->need(self::LINE_HEIGHT);
        $this->currentY -= self::LINE_HEIGHT;
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $this->pages[$this->pageNumber]['content'] .= sprintf(
            "BT /F1 10 Tf %.2F %.2F Td (%s) Tj ET\n",
            $x,
            $y,
            $this->escape($key.':')
        );
        $this->pages[$this->pageNumber]['content'] .= sprintf(
            "BT /F2 10 Tf %.2F %.2F Td (%s) Tj ET\n",
            $x + 150,
            $y,
            $this->escape($this->truncate($value, 70))
        );
    }

    public function paragraph(string $text, int $size = 10): void
    {
        $this->ensurePage();
        foreach ($this->wrap($text, 92) as $line) {
            $this->need(self::LINE_HEIGHT);
            $this->currentY -= self::LINE_HEIGHT;
            $this->writeText($line, $size, false);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function table(array $headers, array $rows): void
    {
        $this->ensurePage();
        $tableWidth = self::PAGE_WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $colCount = max(1, count($headers));
        $widths = array_fill(0, $colCount, $tableWidth / $colCount);
        $rowHeight = 18;

        $this->need($rowHeight + 8);
        $this->currentY -= $rowHeight;
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $this->pages[$this->pageNumber]['content'] .= sprintf(
            "0.88 0.91 0.95 rg %.2F %.2F %.2F %.2F re f\n",
            $x,
            $y,
            $tableWidth,
            $rowHeight
        );
        $this->drawRowCells($headers, $widths, $y + 5, true);
        $this->pages[$this->pageNumber]['content'] .= sprintf(
            "0 0 0 RG 0.4 w %.2F %.2F %.2F %.2F re S\n",
            $x,
            $y,
            $tableWidth,
            $rowHeight
        );

        foreach ($rows as $row) {
            $this->need($rowHeight + 4);
            $this->currentY -= $rowHeight;
            $rowY = $this->currentY;
            $this->pages[$this->pageNumber]['content'] .= sprintf(
                "0 0 0 RG 0.3 w %.2F %.2F %.2F %.2F re S\n",
                $x,
                $rowY,
                $tableWidth,
                $rowHeight
            );
            $this->drawRowCells($row, $widths, $rowY + 5, false);
        }

        $this->currentY -= 6;
    }

    public function jpegPage(string $jpeg, int $width, int $height, string $caption = ''): void
    {
        $this->startPage();

        if ($caption !== '') {
            $this->currentY -= 16;
            $this->writeText($caption, 10, true);
            $this->currentY -= 8;
        }

        $maxW = self::PAGE_WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $maxH = $this->currentY - self::MARGIN_BOTTOM - 10;
        $scale = min($maxW / max(1, $width), $maxH / max(1, $height), 1);
        $drawW = $width * $scale;
        $drawH = $height * $scale;
        $x = self::MARGIN_LEFT + (($maxW - $drawW) / 2);
        $y = self::MARGIN_BOTTOM + 8;

        $name = 'Im'.(count($this->pages[$this->pageNumber]['images']) + 1);
        $this->pages[$this->pageNumber]['images'][] = [
            'name' => $name,
            'bytes' => $jpeg,
            'width' => $width,
            'height' => $height,
        ];
        $this->pages[$this->pageNumber]['content'] .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $drawW,
            $drawH,
            $x,
            $y,
            $name
        );
    }

    public function output(): string
    {
        $this->ensurePage();
        $this->writeFooter();

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $objectNumber = 0;

        $objectNumber++;
        $offsets[$objectNumber] = strlen($pdf);
        $pagesObj = 2;
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Catalog /Pages {$pagesObj} 0 R >>\nendobj\n";

        $kids = [];
        $cursor = 2;
        $pageMeta = [];
        foreach (range(1, $this->pageNumber) as $page) {
            $pageObj = ++$cursor;
            $contentObj = ++$cursor;
            $imageObjs = [];
            foreach ($this->pages[$page]['images'] as $image) {
                $imageObjs[] = ++$cursor;
            }
            $kids[] = $pageObj.' 0 R';
            $pageMeta[$page] = [
                'page' => $pageObj,
                'content' => $contentObj,
                'images' => $imageObjs,
            ];
        }
        $font1 = ++$cursor;
        $font2 = ++$cursor;

        $objectNumber++;
        $offsets[$objectNumber] = strlen($pdf);
        $kidsList = implode(' ', $kids);
        $count = $this->pageNumber;
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Pages /Kids [{$kidsList}] /Count {$count} >>\nendobj\n";

        foreach (range(1, $this->pageNumber) as $page) {
            $meta = $pageMeta[$page];
            $xobjects = '';
            foreach ($this->pages[$page]['images'] as $index => $image) {
                $xobjects .= sprintf(' /%s %d 0 R', $image['name'], $meta['images'][$index]);
            }
            $xobjectDict = $xobjects !== '' ? " /XObject <<{$xobjects} >>" : '';

            $objectNumber++;
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= "{$objectNumber} 0 obj\n<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 "
                .self::PAGE_WIDTH.' '.self::PAGE_HEIGHT
                ."] /Contents {$meta['content']} 0 R /Resources << /Font << /F1 {$font1} 0 R /F2 {$font2} 0 R >>{$xobjectDict} >> >>\nendobj\n";

            $stream = $this->pages[$page]['content'];
            $objectNumber++;
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= "{$objectNumber} 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream\nendobj\n";

            foreach ($this->pages[$page]['images'] as $image) {
                $objectNumber++;
                $offsets[$objectNumber] = strlen($pdf);
                $pdf .= "{$objectNumber} 0 obj\n<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($image['bytes'])." >>\nstream\n{$image['bytes']}\nendstream\nendobj\n";
            }
        }

        $objectNumber++;
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

        $objectNumber++;
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $xrefStart = strlen($pdf);
        $objectCount = $objectNumber + 1;
        $pdf .= "xref\n0 {$objectCount}\n";
        $pdf .= sprintf("%010d 65535 f \n", 0);
        for ($i = 1; $i <= $objectNumber; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$objectCount} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF\n";

        return $pdf;
    }

    private function ensurePage(): void
    {
        if ($this->pageNumber === 0) {
            $this->startPage();
        }
    }

    private function need(float $height): void
    {
        if ($this->currentY - $height < self::MARGIN_BOTTOM + 18) {
            $this->startPage();
        }
    }

    private function writeFooter(): void
    {
        if ($this->pageNumber < 1) {
            return;
        }

        // Running credits are stamped onto every compiled page after merge.
    }

    private function writeText(string $text, int $size, bool $bold): void
    {
        $font = $bold ? 'F1' : 'F2';
        $this->pages[$this->pageNumber]['content'] .= sprintf(
            "BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            self::MARGIN_LEFT,
            $this->currentY,
            $this->escape($text)
        );
    }

    /**
     * @param  list<string>  $cells
     * @param  list<float>  $widths
     */
    private function drawRowCells(array $cells, array $widths, float $textY, bool $bold): void
    {
        $colX = self::MARGIN_LEFT + 4;
        $font = $bold ? 'F1' : 'F2';
        foreach ($widths as $i => $width) {
            $cell = $this->truncate((string) ($cells[$i] ?? ''), max(8, (int) ($width / 5.2)));
            $this->pages[$this->pageNumber]['content'] .= sprintf(
                "BT /%s 9 Tf %.2F %.2F Td (%s) Tj ET\n",
                $font,
                $colX,
                $textY,
                $this->escape($cell)
            );
            $colX += $width;
        }
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $maxChars): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $next = $current === '' ? $word : $current.' '.$word;
            if (strlen($next) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $next;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return strlen($text) > $max ? substr($text, 0, $max - 3).'...' : $text;
    }

    private function escape(string $text): string
    {
        $text = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);

        return preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
    }
}
