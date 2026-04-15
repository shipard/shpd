<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

/**
 * Extracts metadata from uploaded files.
 *
 * For images: width and height.
 * For PDFs: page count.
 *
 * Extraction failures are non-fatal — returns null.
 */
class MetadataExtractor
{
    /**
     * Extract metadata based on MIME type.
     *
     * @return array<string, mixed>|null  Metadata array or null if extraction fails/not supported
     */
    public function extract(string $filePath, string $mimeType): ?array
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml'
                => $this->extractImage($filePath),
            $mimeType === 'image/svg+xml'
                => $this->extractSvg($filePath),
            $mimeType === 'application/pdf'
                => $this->extractPdf($filePath),
            default => null,
        };
    }

    /**
     * Extract width and height from a raster image.
     */
    private function extractImage(string $filePath): ?array
    {
        $info = @getimagesize($filePath);
        if ($info === false) {
            return null;
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
        ];
    }

    /**
     * Extract width and height from an SVG file.
     */
    private function extractSvg(string $filePath): ?array
    {
        $content = @file_get_contents($filePath, false, null, 0, 4096);
        if ($content === false) {
            return null;
        }

        // Try to parse width/height from the <svg> tag
        if (preg_match('/<svg[^>]*\bwidth=["\'](\d+(?:\.\d+)?)["\']/', $content, $wMatch)
            && preg_match('/<svg[^>]*\bheight=["\'](\d+(?:\.\d+)?)["\']/', $content, $hMatch)) {
            return [
                'width' => (int) round((float) $wMatch[1]),
                'height' => (int) round((float) $hMatch[1]),
            ];
        }

        // Try viewBox
        if (preg_match('/<svg[^>]*\bviewBox=["\'](\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)["\']/', $content, $vbMatch)) {
            return [
                'width' => (int) round((float) $vbMatch[3]),
                'height' => (int) round((float) $vbMatch[4]),
            ];
        }

        return null;
    }

    /**
     * Extract page count from a PDF using pdfinfo.
     */
    private function extractPdf(string $filePath): ?array
    {
        $cmd = sprintf('pdfinfo %s 2>/dev/null', escapeshellarg($filePath));
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }

        foreach ($output as $line) {
            if (preg_match('/^Pages:\s+(\d+)/', $line, $m)) {
                return [
                    'pages' => (int) $m[1],
                ];
            }
        }

        return null;
    }
}
