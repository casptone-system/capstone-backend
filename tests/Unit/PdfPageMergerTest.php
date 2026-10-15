<?php

namespace Tests\Unit;

use App\Support\PdfPageMerger;
use PHPUnit\Framework\TestCase;

class PdfPageMergerTest extends TestCase
{
    public function test_indirect_stream_length_is_kept_intact(): void
    {
        $payload = str_repeat('EVIDENCE-PAGE-BODY-', 40);
        $pdf = $this->pdfWithIndirectLength($payload);
        $merged = (new PdfPageMerger)->merge([$pdf]);

        $this->assertStringContainsString($payload, $merged);
        $this->assertGreaterThan(strlen($payload), strlen($merged));
    }

    public function test_footnote_keeps_indirect_page_resources(): void
    {
        $payload = str_repeat('SCANNED-PAGE-PIXELS-', 30);
        $pdf = $this->pdfWithIndirectResources($payload);
        $merged = (new PdfPageMerger)->merge([$pdf], 'Prepared by Area Chair');

        $this->assertStringContainsString($payload, $merged);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page(?!s)/', $merged));

        $page = $this->pageDictionary($merged);
        $this->assertSame(1, preg_match_all('/\/Resources\b/', $page));
        $this->assertMatchesRegularExpression('/\/Resources\s+(\d+)\s+\d+\s+R/', $page);

        preg_match('/\/Resources\s+(\d+)\s+\d+\s+R/', $page, $resource);
        $resourceBody = $this->objectBody($merged, (int) $resource[1]);
        $this->assertStringContainsString('/Im0', $resourceBody);
        $this->assertStringContainsString('/FtNote', $resourceBody);
        $this->assertStringContainsString('/FtGs', $resourceBody);
        $this->assertStringContainsString('0 Tr', $merged);
        $this->assertStringContainsString('Prepared by Area Chair', $merged);
        $this->assertStringNotContainsString('/Resources << /Font << /FtNote', $page);
    }

    public function test_demo_pdf_without_xref_still_imports(): void
    {
        $pdf = "%PDF-1.4\n".
            "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n".
            "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n".
            "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n".
            "4 0 obj<</Length 44>>stream\n".
            "BT /F1 16 Tf 72 720 Td (Visible evidence line) Tj ET\n".
            "endstream\nendobj\n".
            "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n".
            "trailer<</Root 1 0 R>>\n%%EOF\n";

        $merged = (new PdfPageMerger)->merge([$pdf]);

        $this->assertStringContainsString('Visible evidence line', $merged);
    }

    private function pdfWithIndirectLength(string $payload): string
    {
        $stream = "BT /F1 12 Tf 72 720 Td ({$payload}) Tj ET\n";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 6 0 R >> >> >>',
            4 => "<< /Length 5 0 R >>\nstream\n{$stream}endstream",
            5 => (string) strlen($stream),
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 7\n";
        $pdf .= sprintf("%010d 65535 f \n", 0);
        for ($id = 1; $id <= 6; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private function pdfWithIndirectResources(string $payload): string
    {
        $stream = "q\n612 0 0 792 0 0 cm\n/Im0 Do\nQ\nBT /F1 12 Tf 72 720 Td ({$payload}) Tj ET\n";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources 7 0 R >>',
            4 => "<< /Length 5 0 R >>\nstream\n{$stream}endstream",
            5 => (string) strlen($stream),
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            7 => '<< /Font << /F1 6 0 R >> /XObject << /Im0 8 0 R >> /ProcSet [/PDF /Text /ImageB] >>',
            8 => '<< /Type /XObject /Subtype /Image /Width 8 /Height 8 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 9 0 R >>'."\nstream\n".str_repeat("\x10", 64).'endstream',
            9 => '64',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n";
        $pdf .= sprintf("%010d 65535 f \n", 0);
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private function pageDictionary(string $pdf): string
    {
        preg_match_all('/(\d+)\s+\d+\s+obj\s*(.*?)\s*endobj/s', $pdf, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (preg_match('/\/Type\s*\/Page(?!s)/', $match[2]) && ! str_contains($match[2], 'stream')) {
                return $match[2];
            }
        }

        $this->fail('Merged PDF has no page dictionary.');
    }

    private function objectBody(string $pdf, int $id): string
    {
        if (! preg_match('/'.preg_quote((string) $id, '/').'\s+\d+\s+obj\s*(.*?)\s*endobj/s', $pdf, $match)) {
            $this->fail("Object {$id} is missing.");
        }

        return $match[1];
    }
}
