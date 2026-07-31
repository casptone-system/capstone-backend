<?php

namespace App\Services;

class PdfExportService
{
    /**
     * PDF page width in points (A4).
     */
    private const PAGE_WIDTH = 595.28;

    /**
     * PDF page height in points (A4).
     */
    private const PAGE_HEIGHT = 841.89;

    /**
     * Left margin in points.
     */
    private const MARGIN_LEFT = 40;

    /**
     * Right margin in points.
     */
    private const MARGIN_RIGHT = 40;

    /**
     * Top margin in points.
     */
    private const MARGIN_TOP = 50;

    /**
     * Bottom margin in points.
     */
    private const MARGIN_BOTTOM = 50;

    /**
     * Line height in points.
     */
    private const LINE_HEIGHT = 16;

    /**
     * Font size for body text.
     */
    private const FONT_SIZE = 10;

    /**
     * Font size for headers.
     */
    private const HEADER_FONT_SIZE = 14;

    /**
     * Font size for title.
     */
    private const TITLE_FONT_SIZE = 20;

    /**
     * Objects in the PDF.
     *
     * @var array<int, string>
     */
    private array $objects = [];

    /**
     * Current Y position on the page.
     */
    private float $currentY = 0;

    /**
     * Current page number.
     */
    private int $pageNumber = 0;

    /**
     * Pages content streams.
     *
     * @var array<int, string>
     */
    private array $pages = [];

    /**
     * Title of the report.
     */
    private string $title = '';

    /**
     * Generate a PDF from report data.
     *
     * @param array $data Report data
     * @param string $filename Output filename
     * @return \Illuminate\Http\Response
     */
    public function generate(array $data, string $filename)
    {
        $this->title = $data['reportType'] ?? 'Report';
        $this->objects = [];
        $this->pages = [];
        $this->pageNumber = 0;

        $this->startPage();
        $this->writeTitle($this->title);
        $this->writeSubtitle('Generated: ' . ($data['generatedAt'] ?? now()->toDateTimeString()));

        // Write report-specific content
        $this->writeReportContent($data);

        $this->endPage();

        $pdf = $this->buildPdf();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
            'Content-Length' => strlen($pdf),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    /**
     * Start a new page.
     */
    private function startPage(): void
    {
        $this->pageNumber++;
        $this->currentY = self::PAGE_HEIGHT - self::MARGIN_TOP;
        $this->pages[$this->pageNumber] = '';
    }

    /**
     * End the current page.
     */
    private function endPage(): void
    {
        // Add page number footer
        $footerY = self::MARGIN_BOTTOM - 10;
        $footerText = "Page {$this->pageNumber}";
        $footerX = self::PAGE_WIDTH - self::MARGIN_RIGHT - 50;
        $this->pages[$this->pageNumber] .= "BT /F2 8 Tf {$footerX} {$footerY} Td ({$footerText}) Tj ET\n";
    }

    /**
     * Check if we need a new page.
     */
    private function checkPageBreak(float $neededHeight = 20): void
    {
        if ($this->currentY - $neededHeight < self::MARGIN_BOTTOM + 20) {
            $this->endPage();
            $this->startPage();
        }
    }

    /**
     * Write the title.
     */
    private function writeTitle(string $title): void
    {
        $this->currentY -= self::TITLE_FONT_SIZE + 5;
        $escapedTitle = $this->escapeText($title);
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $this->pages[$this->pageNumber] .= "BT /F1 {$this->titleFontSize()} Tf {$x} {$y} Td ({$escapedTitle}) Tj ET\n";
        $this->currentY -= 5;
    }

    /**
     * Get title font size.
     */
    private function titleFontSize(): int
    {
        return self::TITLE_FONT_SIZE;
    }

    /**
     * Write a subtitle.
     */
    private function writeSubtitle(string $subtitle): void
    {
        $this->currentY -= self::FONT_SIZE + 2;
        $escaped = $this->escapeText($subtitle);
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $this->pages[$this->pageNumber] .= "BT /F2 " . (self::FONT_SIZE - 1) . " Tf {$x} {$y} Td ({$escaped}) Tj ET\n";
        $this->currentY -= 8;
    }

    /**
     * Write a section header.
     */
    private function writeSectionHeader(string $header): void
    {
        $this->checkPageBreak(30);
        $this->currentY -= 5;
        $this->currentY -= self::HEADER_FONT_SIZE + 2;
        $escaped = $this->escapeText($header);
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $this->pages[$this->pageNumber] .= "BT /F1 " . self::HEADER_FONT_SIZE . " Tf {$x} {$y} Td ({$escaped}) Tj ET\n";
        $this->currentY -= 5;
    }

    /**
     * Write a line of text.
     */
    private function writeText(string $text, int $fontSize = null): void
    {
        $fontSize = $fontSize ?? self::FONT_SIZE;
        $this->checkPageBreak(self::LINE_HEIGHT);
        $this->currentY -= self::LINE_HEIGHT;
        $escaped = $this->escapeText($text);
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $this->pages[$this->pageNumber] .= "BT /F2 {$fontSize} Tf {$x} {$y} Td ({$escaped}) Tj ET\n";
    }

    /**
     * Write a key-value pair.
     */
    private function writeKeyValue(string $key, string $value): void
    {
        $this->checkPageBreak(self::LINE_HEIGHT);
        $this->currentY -= self::LINE_HEIGHT;
        $escapedKey = $this->escapeText($key . ':');
        $escapedValue = $this->escapeText($value);
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $labelWidth = 150;
        $valueX = $x + $labelWidth;
        $this->pages[$this->pageNumber] .= "BT /F2 " . self::FONT_SIZE . " Tf {$x} {$y} Td ({$escapedKey}) Tj ET\n";
        $this->pages[$this->pageNumber] .= "BT /F2 " . self::FONT_SIZE . " Tf {$valueX} {$y} Td ({$escapedValue}) Tj ET\n";
    }

    /**
     * Write a table.
     *
     * @param array $headers Column headers
     * @param array $rows Table rows
     * @param array $widths Column widths (optional)
     */
    private function writeTable(array $headers, array $rows, array $widths = []): void
    {
        $tableWidth = self::PAGE_WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $colCount = count($headers);

        if (empty($widths)) {
            $colWidth = $tableWidth / $colCount;
            $widths = array_fill(0, $colCount, $colWidth);
        }

        // Normalize widths to fill table width
        $totalWidth = array_sum($widths);
        if ($totalWidth > 0) {
            $scale = $tableWidth / $totalWidth;
            $widths = array_map(fn($w) => $w * $scale, $widths);
        }

        $rowHeight = 18;
        $headerHeight = 20;

        // Check for page break before table
        $neededHeight = $headerHeight + (count($rows) > 0 ? min(count($rows), 5) * $rowHeight : $rowHeight);
        $this->checkPageBreak($neededHeight);

        // Draw header background
        $this->currentY -= $headerHeight;
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $w = $tableWidth;
        $h = $headerHeight;
        $this->pages[$this->pageNumber] .= "0.8 0.8 0.8 rg {$x} {$y} {$w} {$h} re f\n";

        // Draw header text
        $colX = self::MARGIN_LEFT;
        foreach ($headers as $i => $header) {
            $escaped = $this->escapeText($header);
            $textY = $this->currentY + 5;
            $this->pages[$this->pageNumber] .= "BT /F1 " . (self::FONT_SIZE) . " Tf {$colX} {$textY} Td ({$escaped}) Tj ET\n";
            $colX += $widths[$i];
        }

        // Draw header border
        $this->pages[$this->pageNumber] .= "0 0 0 RG 0.5 w {$x} {$y} {$w} {$h} re S\n";

        // Draw rows
        foreach ($rows as $row) {
            $this->checkPageBreak($rowHeight + 5);

            $this->currentY -= $rowHeight;
            $colX = self::MARGIN_LEFT;
            $rowY = $this->currentY;

            // Draw row border
            $this->pages[$this->pageNumber] .= "0 0 0 RG 0.3 w {$x} {$rowY} {$w} {$rowHeight} re S\n";

            // Draw cell text
            foreach ($row as $i => $cell) {
                if ($i >= $colCount) {
                    break;
                }
                $cellText = is_array($cell) ? json_encode($cell) : (string) $cell;
                // Truncate text if too long
                $maxChars = (int) ($widths[$i] / 5);
                if (strlen($cellText) > $maxChars) {
                    $cellText = substr($cellText, 0, $maxChars - 3) . '...';
                }
                $escaped = $this->escapeText($cellText);
                $textY = $this->currentY + 4;
                $this->pages[$this->pageNumber] .= "BT /F2 " . (self::FONT_SIZE - 1) . " Tf {$colX} {$textY} Td ({$escaped}) Tj ET\n";
                $colX += $widths[$i];
            }
        }
        $this->currentY -= 5;
    }

    /**
     * Write report-specific content based on report type.
     */
    private function writeReportContent(array $data): void
    {
        // Write entity info if present
        if (isset($data['program'])) {
            $this->writeSectionHeader('Program Information');
            foreach ($data['program'] as $key => $value) {
                $this->writeKeyValue($this->formatLabel($key), (string) ($value ?? 'N/A'));
            }
        }

        if (isset($data['college'])) {
            $this->writeSectionHeader('College Information');
            foreach ($data['college'] as $key => $value) {
                $this->writeKeyValue($this->formatLabel($key), (string) ($value ?? 'N/A'));
            }
        }

        if (isset($data['area'])) {
            $this->writeSectionHeader('Area Information');
            foreach ($data['area'] as $key => $value) {
                $this->writeKeyValue($this->formatLabel($key), (string) ($value ?? 'N/A'));
            }
        }

        if (isset($data['cycle'])) {
            $this->writeSectionHeader('Accreditation Cycle Information');
            foreach ($data['cycle'] as $key => $value) {
                $this->writeKeyValue($this->formatLabel($key), (string) ($value ?? 'N/A'));
            }
        }

        // Write summary
        if (isset($data['summary'])) {
            $this->writeSectionHeader('Summary');
            foreach ($data['summary'] as $key => $value) {
                $this->writeKeyValue($this->formatLabel($key), (string) ($value ?? '0'));
            }
        }

        // Write areas table
        if (isset($data['areas']) && count($data['areas']) > 0) {
            $this->writeSectionHeader('Areas');
            $headers = ['Area Name', 'Status', 'Program', 'Chair', 'Documents', 'Compliance'];
            $rows = array_map(fn($a) => [
                $a['areaName'] ?? '',
                $a['areaStatus'] ?? '',
                $a['programName'] ?? $a['programCode'] ?? '',
                $a['chairName'] ?? '',
                (string) ($a['documentCount'] ?? 0),
                $a['complianceLevel'] ?? ($a['hasEvidence'] ? 'Compliant' : 'Non-Compliant'),
            ], $data['areas']);
            $this->writeTable($headers, $rows, [150, 80, 100, 100, 60, 80]);
        }

        // Write programs table
        if (isset($data['programs']) && count($data['programs']) > 0) {
            $this->writeSectionHeader('Programs');
            $headers = ['Program', 'Code', 'Areas', 'Documents', 'Tasks', 'Overdue', 'Compliance %'];
            $rows = array_map(fn($p) => [
                $p['programName'] ?? '',
                $p['programCode'] ?? '',
                (string) ($p['totalAreas'] ?? 0),
                (string) ($p['totalDocuments'] ?? 0),
                (string) ($p['totalTasks'] ?? 0),
                (string) ($p['overdueTasks'] ?? 0),
                (string) ($p['compliancePercent'] ?? 0) . '%',
            ], $data['programs']);
            $this->writeTable($headers, $rows, [120, 60, 50, 70, 50, 50, 70]);
        }

        // Write cycles
        if (isset($data['cycles']) && count($data['cycles']) > 0) {
            $this->writeSectionHeader('Accreditation Cycles');
            $headers = ['Level', 'Status', 'Readiness', 'Areas', 'Documents', 'Tasks', 'Overdue'];
            $rows = array_map(fn($c) => [
                $c['level'] ?? '',
                $c['status'] ?? '',
                $c['readiness'] ?? '',
                (string) ($c['totalAreas'] ?? 0),
                (string) ($c['totalDocuments'] ?? 0),
                (string) ($c['totalTasks'] ?? 0),
                (string) ($c['overdueTasks'] ?? 0),
            ], $data['cycles']);
            $this->writeTable($headers, $rows, [60, 80, 70, 50, 70, 50, 50]);
        }

        // Write documents table
        if (isset($data['documents']) && count($data['documents']) > 0) {
            $this->writeSectionHeader('Documents');
            $headers = ['Title', 'Status', 'Version', 'Uploaded By', 'Created'];
            $rows = array_map(fn($d) => [
                $d['title'] ?? '',
                $d['status'] ?? '',
                (string) ($d['currentVersion'] ?? 0),
                $d['uploadedBy'] ?? '',
                $d['createdAt'] ?? '',
            ], $data['documents']);
            $this->writeTable($headers, $rows, [150, 60, 50, 100, 100]);
        }

        // Write tasks table
        if (isset($data['tasks']) && count($data['tasks']) > 0) {
            $this->writeSectionHeader('Tasks');
            $headers = ['Title', 'Priority', 'Status', 'Due Date', 'Created By'];
            $rows = array_map(fn($t) => [
                $t['title'] ?? '',
                $t['priority'] ?? '',
                $t['status'] ?? '',
                $t['dueDate'] ?? 'N/A',
                $t['createdBy'] ?? '',
            ], $data['tasks']);
            $this->writeTable($headers, $rows, [150, 60, 80, 80, 100]);
        }

        // Write reviews table
        if (isset($data['reviews']) && count($data['reviews']) > 0) {
            $this->writeSectionHeader('Reviews');
            $headers = ['Status', 'Submitted By', 'Submitted At', 'Comments', 'Terminal'];
            $rows = array_map(fn($r) => [
                $r['currentStatus'] ?? '',
                $r['submittedBy'] ?? '',
                $r['submittedAt'] ?? 'N/A',
                (string) ($r['commentCount'] ?? 0),
                $r['isTerminal'] ? 'Yes' : 'No',
            ], $data['reviews']);
            $this->writeTable($headers, $rows, [100, 100, 100, 50, 50]);
        }

        // Write members table
        if (isset($data['members']) && count($data['members']) > 0) {
            $this->writeSectionHeader('Members');
            $headers = ['Name', 'Email', 'Role'];
            $rows = array_map(fn($m) => [
                $m['name'] ?? '',
                $m['email'] ?? '',
                $m['role'] ?? '',
            ], $data['members']);
            $this->writeTable($headers, $rows, [150, 200, 100]);
        }
    }

    /**
     * Format a label from camelCase to Title Case.
     */
    private function formatLabel(string $key): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        return ucfirst($result);
    }

    /**
     * Escape text for PDF.
     */
    private function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace("\r", '', $text);
        $text = str_replace("\n", ' ', $text);
        // Remove non-ASCII characters
        $text = preg_replace('/[^\x20-\x7E]/', '', $text);
        return $text;
    }

    /**
     * Build the final PDF string.
     */
    private function buildPdf(): string
    {
        $pdf = "%PDF-1.4\n";
        $objectOffsets = [];
        $objectNumber = 0;

        // Object 1: Catalog
        $objectNumber++;
        $objectOffsets[$objectNumber] = strlen($pdf);
        $kidsList = implode(' ', array_map(fn($p) => $this->pageObjectNumber($p) . ' 0 R', range(1, $this->pageNumber)));
        $pagesObjNum = $objectNumber + 1;
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Catalog /Pages {$pagesObjNum} 0 R >>\nendobj\n";

        // Object 2: Pages
        $objectNumber++;
        $objectOffsets[$objectNumber] = strlen($pdf);
        $pageCount = $this->pageNumber;
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Pages /Kids [{$kidsList}] /Count {$pageCount} >>\nendobj\n";

        // Font objects
        $font1ObjNum = $objectNumber + 1 + ($pageCount * 2);
        $font2ObjNum = $font1ObjNum + 1;

        // Page objects and content streams
        for ($p = 1; $p <= $this->pageNumber; $p++) {
            // Page object
            $objectNumber++;
            $objectOffsets[$objectNumber] = strlen($pdf);
            $contentObjNum = $objectNumber + 1;
            $pdf .= "{$objectNumber} 0 obj\n<< /Type /Page /Parent {$pagesObjNum} 0 R /MediaBox [0 0 " . self::PAGE_WIDTH . " " . self::PAGE_HEIGHT . "] /Contents {$contentObjNum} 0 R /Resources << /Font << /F1 {$font1ObjNum} 0 R /F2 {$font2ObjNum} 0 R >> >> >>\nendobj\n";

            // Content stream
            $objectNumber++;
            $objectOffsets[$objectNumber] = strlen($pdf);
            $streamContent = $this->pages[$p];
            $streamLength = strlen($streamContent);
            $pdf .= "{$objectNumber} 0 obj\n<< /Length {$streamLength} >>\nstream\n{$streamContent}endstream\nendobj\n";
        }

        // Font 1: Helvetica-Bold
        $objectNumber++;
        $objectOffsets[$objectNumber] = strlen($pdf);
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

        // Font 2: Helvetica
        $objectNumber++;
        $objectOffsets[$objectNumber] = strlen($pdf);
        $pdf .= "{$objectNumber} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        // Cross-reference table
        $xrefStart = strlen($pdf);
        $objectCount = $objectNumber + 1;
        $pdf .= "xref\n0 {$objectCount}\n";
        $pdf .= sprintf("%010d 65535 f \n", 0);
        for ($i = 1; $i <= $objectNumber; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $objectOffsets[$i]);
        }

        // Trailer
        $pdf .= "trailer\n<< /Size {$objectCount} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF\n";

        return $pdf;
    }

    /**
     * Get the object number for a page.
     */
    private function pageObjectNumber(int $pageNum): int
    {
        // Page objects start at object 3, with 2 objects per page (page + content)
        return 2 + ($pageNum * 2) - 1;
    }
}