<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Attachments\ThumbnailGenerator;

class ThumbnailGeneratorTest extends TestCase
{
    private ThumbnailGenerator $generator;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->generator = new ThumbnailGenerator();
        $this->tempDir = sys_get_temp_dir() . '/shpd_thumb_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/cache/thumbnails', 0755, true);
    }

    protected function tearDown(): void
    {
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

        // We can't easily create a test PDF without dependencies.
        // Skip if no test PDF is available.
        $this->markTestSkipped('Requires a test PDF fixture');
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
