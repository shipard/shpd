<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

use Shipard\Core\Logging\ErrorLogger;

/**
 * Extrakce plného textu z příloh pro fulltext (`extracted_text` sloupce,
 * index `ft_text`). Best-effort — každé selhání (nepodporovaný MIME,
 * chybějící binárka, rozbitý soubor) vrací null a nesmí blokovat volající
 * flow (zařazení do Spisovny apod.).
 *
 * Podporované formáty:
 * - PDF → text přes pdftotext -layout (poppler-utils, sdílený balíček
 *   s pdftocairo z ThumbnailGeneratoru)
 * - text/* → přímé čtení souboru
 *
 * Výstup je UTF-8 sanitizovaný a zastropovaný na MAX_LENGTH znaků
 * (`mediumtext` má rezervu; fulltext index delší tail stejně nevyužije).
 */
class TextExtractor
{
    /** Strop délky extrahovaného textu ve znacích. */
    public const MAX_LENGTH = 500_000;

    // Binary name as property — test seam for simulating a missing tool.
    protected string $pdftotextBin = 'pdftotext';

    public function extract(string $filePath, string $mimeType): ?string
    {
        if (!is_file($filePath)) {
            return null;
        }

        $text = match (true) {
            $mimeType === 'application/pdf'     => $this->extractPdf($filePath),
            str_starts_with($mimeType, 'text/') => $this->extractPlainText($filePath),
            default => null,
        };
        if ($text === null) {
            return null;
        }

        $text = $this->sanitize($text);
        return $text === '' ? null : $text;
    }

    /**
     * pdftotext -layout {input} - (stdout). Layout mode drží sloupce
     * tabulek u sebe — čitelnější pro fulltext i případné LLM čtení.
     */
    protected function extractPdf(string $filePath): ?string
    {
        $cmd = sprintf(
            '%s -layout %s -',
            $this->pdftotextBin,
            escapeshellarg($filePath),
        );

        exec($cmd . ' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $ctx = ['file' => basename($filePath), 'exitCode' => $exitCode];
            if ($exitCode === 127) {
                ErrorLogger::warn("text extraction: tool '{$this->pdftotextBin}' is not installed — sudo apt install poppler-utils", $ctx);
            } else {
                ErrorLogger::warn("text extraction: tool '{$this->pdftotextBin}' failed", $ctx);
            }
            return null;
        }

        return implode("\n", $output);
    }

    protected function extractPlainText(string $filePath): ?string
    {
        // Čte se s rezervou nad strop (multibyte znaky), zbytek ořízne sanitize.
        $content = @file_get_contents($filePath, false, null, 0, self::MAX_LENGTH * 4);
        return $content === false ? null : $content;
    }

    /**
     * UTF-8 sanitizace (nevalidní sekvence → vypuštěné), odstranění řídicích
     * znaků kromě \n a \t, cap na MAX_LENGTH znaků.
     */
    protected function sanitize(string $text): string
    {
        // iconv //IGNORE nevalidní sekvence vypouští (mb_convert_encoding by
        // je nahrazovalo '?'); fallback pro případ selhání iconv.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        $text = $clean !== false ? $clean : (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[^\P{C}\n\t]+/u', '', $text);
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }
        return $text;
    }
}
