<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Attachments\MetadataExtractor;

class MetadataExtractorTest extends TestCase
{
    private MetadataExtractor $extractor;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->extractor = new MetadataExtractor();
        $this->tempDir = sys_get_temp_dir() . '/shpd_meta_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    // --- Images ---

    public function testExtractPngDimensions(): void
    {
        // Minimal valid 2x3 PNG created from raw bytes
        $path = $this->tempDir . '/test.png';
        file_put_contents($path, $this->createMinimalPng(200, 150));

        $meta = $this->extractor->extract($path, 'image/png');

        $this->assertNotNull($meta);
        $this->assertSame(200, $meta['width']);
        $this->assertSame(150, $meta['height']);
    }

    public function testExtractJpegDimensions(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $img = imagecreatetruecolor(800, 600);
        $path = $this->tempDir . '/test.jpg';
        imagejpeg($img, $path);
        // imagedestroy() is a no-op since PHP 8.0 and deprecated in 8.5

        $meta = $this->extractor->extract($path, 'image/jpeg');

        $this->assertNotNull($meta);
        $this->assertSame(800, $meta['width']);
        $this->assertSame(600, $meta['height']);
    }

    // --- SVG ---

    public function testExtractSvgWithWidthHeight(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect/></svg>';
        $path = $this->tempDir . '/test.svg';
        file_put_contents($path, $svg);

        $meta = $this->extractor->extract($path, 'image/svg+xml');

        $this->assertNotNull($meta);
        $this->assertSame(400, $meta['width']);
        $this->assertSame(300, $meta['height']);
    }

    public function testExtractSvgWithViewBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 768"><rect/></svg>';
        $path = $this->tempDir . '/test.svg';
        file_put_contents($path, $svg);

        $meta = $this->extractor->extract($path, 'image/svg+xml');

        $this->assertNotNull($meta);
        $this->assertSame(1024, $meta['width']);
        $this->assertSame(768, $meta['height']);
    }

    // --- Unsupported ---

    public function testExtractUnsupportedReturnsNull(): void
    {
        $path = $this->tempDir . '/test.txt';
        file_put_contents($path, 'Hello world');

        $meta = $this->extractor->extract($path, 'text/plain');
        $this->assertNull($meta);
    }

    public function testExtractNonExistentFileReturnsNull(): void
    {
        $meta = $this->extractor->extract('/nonexistent/file.png', 'image/png');
        $this->assertNull($meta);
    }

    // --- helpers ---

    /**
     * Create a minimal valid PNG file with given dimensions.
     * Builds the PNG binary from scratch — no GD dependency.
     */
    private function createMinimalPng(int $width, int $height): string
    {
        $signature = "\x89PNG\r\n\x1a\n";

        // IHDR chunk: width(4) + height(4) + bitDepth(1) + colorType(1) + compression(1) + filter(1) + interlace(1) = 13 bytes
        $ihdrData = pack('N', $width) . pack('N', $height) . "\x08\x02\x00\x00\x00"; // 8-bit RGB
        $ihdr = $this->pngChunk('IHDR', $ihdrData);

        // IDAT chunk: minimal image data (one row of zeros, deflated)
        // Each row: filter byte (0) + RGB pixels
        $rawRow = "\x00" . str_repeat("\x00", $width * 3);
        $rawData = str_repeat($rawRow, $height);
        $compressed = gzcompress($rawData);
        $idat = $this->pngChunk('IDAT', $compressed);

        // IEND chunk
        $iend = $this->pngChunk('IEND', '');

        return $signature . $ihdr . $idat . $iend;
    }

    private function pngChunk(string $type, string $data): string
    {
        $chunk = $type . $data;
        return pack('N', strlen($data)) . $chunk . pack('N', crc32($chunk));
    }

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
