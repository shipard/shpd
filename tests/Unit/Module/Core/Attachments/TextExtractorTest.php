<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Attachments\TextExtractor;

/** TextExtractor s podvrženou binárkou — simulace chybějícího pdftotext. */
class MissingBinaryTextExtractor extends TextExtractor
{
    protected string $pdftotextBin = 'pdftotext-does-not-exist-xyz';
}

class TextExtractorTest extends TestCase
{
    private TextExtractor $extractor;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->extractor = new TextExtractor();
        $this->tempDir = sys_get_temp_dir() . '/shpd_text_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    /** Minimální ručně psané PDF s jedním textovým obsahem (poppler si xref zrekonstruuje). */
    private function writeMiniPdf(string $text): string
    {
        $content = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $pdf = "%PDF-1.4\n"
            . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]"
            . " /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            . '4 0 obj << /Length ' . strlen($content) . " >> stream\n"
            . $content . "\nendstream endobj\n"
            . "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n";
        $path = $this->tempDir . '/test.pdf';
        file_put_contents($path, $pdf);
        return $path;
    }

    public function testExtractPdf(): void
    {
        if (!$this->pdftotextAvailable()) {
            $this->markTestSkipped('pdftotext not installed');
        }

        $path = $this->writeMiniPdf('Hello Shipard registry');
        $text = $this->extractor->extract($path, 'application/pdf');

        $this->assertNotNull($text);
        $this->assertStringContainsString('Hello Shipard registry', $text);
    }

    public function testExtractPlainText(): void
    {
        $path = $this->tempDir . '/note.txt';
        file_put_contents($path, "Pojistná smlouva č. 123\ndruhý řádek");

        $text = $this->extractor->extract($path, 'text/plain');

        $this->assertSame("Pojistná smlouva č. 123\ndruhý řádek", $text);
    }

    public function testUnsupportedMimeReturnsNull(): void
    {
        $path = $this->tempDir . '/img.png';
        file_put_contents($path, 'fake-png');

        $this->assertNull($this->extractor->extract($path, 'image/png'));
        $this->assertNull($this->extractor->extract($path, 'application/zip'));
    }

    public function testMissingFileReturnsNull(): void
    {
        $this->assertNull($this->extractor->extract($this->tempDir . '/nope.pdf', 'application/pdf'));
    }

    public function testMissingBinaryReturnsNullWithoutException(): void
    {
        $path = $this->writeMiniPdf('irrelevant');
        $extractor = new MissingBinaryTextExtractor();

        $this->assertNull($extractor->extract($path, 'application/pdf'));
    }

    public function testSanitizeStripsControlCharsAndInvalidUtf8(): void
    {
        $path = $this->tempDir . '/dirty.txt';
        file_put_contents($path, "abc\x00\x07def\nghi\tjkl\xFF!");

        $text = $this->extractor->extract($path, 'text/plain');

        $this->assertSame("abcdef\nghi\tjkl!", $text);
    }

    public function testCapsLengthAtMaxLength(): void
    {
        $path = $this->tempDir . '/long.txt';
        file_put_contents($path, str_repeat('a', TextExtractor::MAX_LENGTH + 1000));

        $text = $this->extractor->extract($path, 'text/plain');

        $this->assertNotNull($text);
        $this->assertSame(TextExtractor::MAX_LENGTH, mb_strlen($text));
    }

    public function testEmptyResultReturnsNull(): void
    {
        $path = $this->tempDir . '/empty.txt';
        file_put_contents($path, "  \n\t ");

        $this->assertNull($this->extractor->extract($path, 'text/plain'));
    }

    private function pdftotextAvailable(): bool
    {
        exec('command -v pdftotext 2>/dev/null', $out, $code);
        return $code === 0;
    }
}
