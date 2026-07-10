<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\ThumbnailGenerator;

/**
 * Test seam: overrides the pdftocairo binary name with one that does not
 * exist, so runTool() gets exit code 127 from the shell.
 */
class TestableThumbnailGenerator extends ThumbnailGenerator
{
    public function __construct()
    {
        $this->pdftocairoBin = 'shpd-test-missing-binary-x7q';
    }
}

class ThumbnailGeneratorTest extends TestCase
{
    private ThumbnailGenerator $generator;
    private string $tempDir;
    private string $logPath;
    private string $prevErrorLog;

    protected function setUp(): void
    {
        $this->generator = new ThumbnailGenerator();
        $this->tempDir = sys_get_temp_dir() . '/shpd_thumb_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/cache/thumbnails', 0755, true);

        $this->logPath = $this->tempDir . '/shipard.log';
        ErrorLogger::setLogPath($this->logPath);
        // ErrorLogger::write() duplicates every entry to error_log() — redirect
        // it so warn entries don't pollute the PHPUnit stderr output.
        $this->prevErrorLog = (string) ini_set('error_log', $this->tempDir . '/php_error.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        ini_set('error_log', $this->prevErrorLog);
        $this->rmdirRecursive($this->tempDir);
    }

    // --- supportsThumbnail ---

    public function testSupportsThumbnailPdf(): void
    {
        $this->assertTrue($this->generator->supportsThumbnail('application/pdf'));
    }

    public function testSupportsThumbnailSvg(): void
    {
        $this->assertTrue($this->generator->supportsThumbnail('image/svg+xml'));
    }

    public function testSupportsThumbnailJpeg(): void
    {
        $this->assertTrue($this->generator->supportsThumbnail('image/jpeg'));
    }

    public function testSupportsThumbnailPng(): void
    {
        $this->assertTrue($this->generator->supportsThumbnail('image/png'));
    }

    public function testSupportsThumbnailWebp(): void
    {
        $this->assertTrue($this->generator->supportsThumbnail('image/webp'));
    }

    public function testDoesNotSupportText(): void
    {
        $this->assertFalse($this->generator->supportsThumbnail('text/plain'));
    }

    public function testDoesNotSupportZip(): void
    {
        $this->assertFalse($this->generator->supportsThumbnail('application/zip'));
    }

    public function testDoesNotSupportWord(): void
    {
        $this->assertFalse($this->generator->supportsThumbnail('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    }

    // --- Cache key determinism ---

    public function testCacheHitReturnsExistingFile(): void
    {
        // Pre-create a cache file
        $cacheKey = hash('sha256', '1:300:85:1:abc123');
        $cachePath = $this->tempDir . '/cache/thumbnails/' . $cacheKey . '.jpg';
        file_put_contents($cachePath, 'fake jpeg data');

        // getThumbnail should return the cached file without needing a real source file
        $result = $this->generator->getThumbnail(
            $this->tempDir,
            '/nonexistent/source.jpg', // doesn't need to exist for cache hit
            'image/jpeg',
            1,
            'abc123',
            300,
            85,
            1,
        );

        $this->assertSame($cachePath, $result);
    }

    // --- Image thumbnail generation (requires libvips) ---

    public function testGenerateImageThumbnail(): void
    {
        // Check if vipsthumbnail is available
        exec('which vipsthumbnail 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('vipsthumbnail not available');
        }

        // Create a test PNG from raw bytes (no GD dependency)
        $inputPath = $this->tempDir . '/input.png';
        file_put_contents($inputPath, $this->createMinimalPng(800, 600));

        $outputPath = $this->tempDir . '/output.jpg';

        $result = $this->generator->generateImage($inputPath, $outputPath, 200, 85);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);
    }

    /**
     * Create a minimal valid PNG from raw bytes — no GD dependency.
     */
    private function createMinimalPng(int $width, int $height): string
    {
        $signature = "\x89PNG\r\n\x1a\n";

        $ihdrData = pack('N', $width) . pack('N', $height) . "\x08\x02\x00\x00\x00";
        $ihdr = $this->pngChunk('IHDR', $ihdrData);

        $rawRow = "\x00" . str_repeat("\x00", $width * 3);
        $rawData = str_repeat($rawRow, $height);
        $compressed = gzcompress($rawData);
        $idat = $this->pngChunk('IDAT', $compressed);

        $iend = $this->pngChunk('IEND', '');

        return $signature . $ihdr . $idat . $iend;
    }

    private function pngChunk(string $type, string $data): string
    {
        $chunk = $type . $data;
        return pack('N', strlen($data)) . $chunk . pack('N', crc32($chunk));
    }

    // --- PDF thumbnail generation (requires pdftocairo) ---

    public function testGeneratePdfThumbnail(): void
    {
        exec('which pdftocairo 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('pdftocairo not available');
        }

        // Build a minimal valid one-page PDF from raw bytes — no external
        // tooling needed to author the fixture (same approach as createMinimalPng).
        $inputPath = $this->tempDir . '/input.pdf';
        file_put_contents($inputPath, $this->createMinimalPdf());

        $outputPath = $this->tempDir . '/output.jpg';

        $result = $this->generator->generatePdf($inputPath, $outputPath, 200, 85, 1);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);
    }

    /**
     * Create a minimal valid single-page PDF (blank 200×200 page) from raw
     * bytes, computing xref byte offsets so poppler accepts it — no GD,
     * libvips, or pdf tooling required to author it.
     */
    private function createMinimalPdf(): string
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
        $count = count($objects) + 1; // + the free object 0

        // Each xref entry is exactly 20 bytes ("nnnnnnnnnn ggggg x \n").
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    // --- Tool failure logging ---

    public function testMissingToolReturnsFalse(): void
    {
        $generator = new TestableThumbnailGenerator();
        $inputPath = $this->tempDir . '/input.pdf';
        file_put_contents($inputPath, $this->createMinimalPdf());
        $outputPath = $this->tempDir . '/output.jpg';

        $result = $generator->generatePdf($inputPath, $outputPath, 200, 85, 1);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($outputPath);
    }

    public function testMissingToolLogsWarningWithExitCode127(): void
    {
        $generator = new TestableThumbnailGenerator();
        $inputPath = $this->tempDir . '/input.pdf';
        file_put_contents($inputPath, $this->createMinimalPdf());

        $generator->generatePdf($inputPath, $this->tempDir . '/output.jpg', 200, 85, 1);

        $this->assertFileExists($this->logPath);
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('"exitCode":127', $log);
        $this->assertStringContainsString("tool 'shpd-test-missing-binary-x7q' is not installed", $log);
        $this->assertStringContainsString('sudo apt install poppler-utils', $log);
    }

    public function testFailedToolLogsWarning(): void
    {
        exec('which pdftocairo 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('pdftocairo not available');
        }

        $inputPath = $this->tempDir . '/broken.pdf';
        file_put_contents($inputPath, 'not a pdf');
        $outputPath = $this->tempDir . '/output.jpg';

        $result = $this->generator->generatePdf($inputPath, $outputPath, 200, 85, 1);

        $this->assertFalse($result);
        $this->assertFileExists($this->logPath);
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString("tool 'pdftocairo' failed", $log);
        $this->assertStringContainsString('"exitCode":', $log);
        $this->assertStringNotContainsString('"exitCode":127', $log);
    }

    // --- Unsupported type returns null ---

    public function testGetThumbnailUnsupportedTypeReturnsNull(): void
    {
        $inputPath = $this->tempDir . '/test.txt';
        file_put_contents($inputPath, 'text content');

        $result = $this->generator->getThumbnail(
            $this->tempDir,
            $inputPath,
            'text/plain',
            1,
            'checksum',
        );

        $this->assertNull($result);
    }

    // --- helpers ---

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
