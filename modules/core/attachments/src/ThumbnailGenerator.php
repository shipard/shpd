<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

/**
 * Generates thumbnail images for attachments using CLI tools.
 *
 * Supported formats:
 * - PDF → JPEG via pdftocairo (poppler-utils)
 * - SVG → PNG via rsvg-convert, then JPEG via libvips
 * - Images (JPEG, PNG, GIF, WebP) → JPEG via libvips
 *
 * Thumbnails are cached on disk. Cache key = SHA-256 of parameters.
 */
class ThumbnailGenerator
{
    /**
     * Get or generate a thumbnail for the given attachment.
     *
     * @param string $dsPath       Data source directory
     * @param string $inputPath    Full path to the source file
     * @param string $mimeType     MIME type of the source file
     * @param int    $attachmentId Attachment DB ID (for cache key)
     * @param string $checksum     File checksum (for cache invalidation)
     * @param int    $width        Desired width in pixels
     * @param int    $quality      JPEG quality (1-100)
     * @param int    $page         Page number (for multi-page PDFs)
     * @return string|null  Path to the thumbnail JPEG, or null if generation failed
     */
    public function getThumbnail(
        string $dsPath,
        string $inputPath,
        string $mimeType,
        int $attachmentId,
        string $checksum,
        int $width = 300,
        int $quality = 85,
        int $page = 1,
    ): ?string {
        // Ensure cache directory exists
        $cacheDir = $dsPath . '/cache/thumbnails';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Build cache key
        $cacheKey = hash('sha256', "{$attachmentId}:{$width}:{$quality}:{$page}:{$checksum}");
        $cachePath = $cacheDir . '/' . $cacheKey . '.jpg';

        // Cache hit
        if (file_exists($cachePath)) {
            return $cachePath;
        }

        // Generate based on MIME type
        $success = match (true) {
            $mimeType === 'application/pdf'  => $this->generatePdf($inputPath, $cachePath, $width, $quality, $page),
            $mimeType === 'image/svg+xml'    => $this->generateSvg($inputPath, $cachePath, $width, $quality),
            str_starts_with($mimeType, 'image/') => $this->generateImage($inputPath, $cachePath, $width, $quality),
            default => false,
        };

        return $success && file_exists($cachePath) ? $cachePath : null;
    }

    /**
     * Check whether a MIME type supports thumbnail generation.
     */
    public function supportsThumbnail(string $mimeType): bool
    {
        if ($mimeType === 'application/pdf') {
            return true;
        }
        if ($mimeType === 'image/svg+xml') {
            return true;
        }
        if (str_starts_with($mimeType, 'image/')) {
            return true;
        }
        return false;
    }

    /**
     * Generate thumbnail from PDF using pdftocairo.
     *
     * pdftocairo -jpeg -f {page} -l {page} -scale-to-x {width} -scale-to-y -1 input.pdf output_prefix
     * Output: output_prefix-{page_padded}.jpg
     */
    public function generatePdf(string $inputPath, string $outputPath, int $width, int $quality, int $page): bool
    {
        $tmpPrefix = sys_get_temp_dir() . '/shpd_thumb_' . uniqid();

        $cmd = sprintf(
            'pdftocairo -jpeg -jpegopt quality=%d -f %d -l %d -scale-to-x %d -scale-to-y -1 %s %s 2>/dev/null',
            $quality,
            $page,
            $page,
            $width,
            escapeshellarg($inputPath),
            escapeshellarg($tmpPrefix),
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return false;
        }

        // pdftocairo outputs files like: {prefix}-{page_number}.jpg
        // Page number is zero-padded to match the total page count
        // Find the generated file
        $pattern = $tmpPrefix . '-*.jpg';
        $files = glob($pattern);
        if ($files === false || $files === []) {
            // Try single digit format
            $singleFile = $tmpPrefix . sprintf('-%02d.jpg', $page);
            if (!file_exists($singleFile)) {
                return false;
            }
            $files = [$singleFile];
        }

        $generated = $files[0];
        rename($generated, $outputPath);

        // Clean up any remaining temp files
        foreach (glob($tmpPrefix . '*') ?: [] as $tmpFile) {
            @unlink($tmpFile);
        }

        return true;
    }

    /**
     * Generate thumbnail from SVG using rsvg-convert + libvips.
     */
    public function generateSvg(string $inputPath, string $outputPath, int $width, int $quality): bool
    {
        $tmpPng = sys_get_temp_dir() . '/shpd_thumb_' . uniqid() . '.png';

        // SVG → PNG via rsvg-convert
        $cmd1 = sprintf(
            'rsvg-convert -w %d %s -o %s 2>/dev/null',
            $width,
            escapeshellarg($inputPath),
            escapeshellarg($tmpPng),
        );
        exec($cmd1, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($tmpPng)) {
            @unlink($tmpPng);
            return false;
        }

        // PNG → JPEG via vips
        $cmd2 = sprintf(
            'vips jpegsave %s %s --Q %d 2>/dev/null',
            escapeshellarg($tmpPng),
            escapeshellarg($outputPath),
            $quality,
        );
        exec($cmd2, $output, $exitCode);

        @unlink($tmpPng);
        return $exitCode === 0;
    }

    /**
     * Generate thumbnail from raster image using libvips.
     */
    public function generateImage(string $inputPath, string $outputPath, int $width, int $quality): bool
    {
        $cmd = sprintf(
            'vipsthumbnail %s --size=%d -o %s[Q=%d] 2>/dev/null',
            escapeshellarg($inputPath),
            $width,
            escapeshellarg($outputPath),
            $quality,
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }
}
