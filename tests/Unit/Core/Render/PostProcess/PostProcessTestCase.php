<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Render\PostProcess;

use PHPUnit\Framework\TestCase;

/**
 * Sdílená výbava testů post-processing kroků: minimální validní PDF
 * syntetizované z raw bytes (vzor ThumbnailGeneratorTest) a probe na
 * dostupnost poppler binárek (skip, ne fail — vzor TextExtractorTest).
 */
abstract class PostProcessTestCase extends TestCase
{
    protected function binaryAvailable(string $name): bool
    {
        exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Minimální validní jednostránkové PDF (prázdná stránka 200×200)
     * s korektními xref offsety, aby ho poppler přijal.
     */
    protected function createMinimalPdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    /** Počet stran dle `pdfinfo` — volat jen po probe na poppler. */
    protected function pageCount(string $pdf): int
    {
        $path = sys_get_temp_dir() . '/shpd-render-test-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($path, $pdf);
        try {
            exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $output, $exitCode);
            $this->assertSame(0, $exitCode, 'pdfinfo failed on generated PDF');
            foreach ($output as $line) {
                if (preg_match('/^Pages:\s+(\d+)/', $line, $m)) {
                    return (int) $m[1];
                }
            }
            $this->fail('pdfinfo output has no Pages line');
        } finally {
            @unlink($path);
        }
    }
}
