<?php

declare(strict_types=1);

namespace Shipard\Core\Render\PostProcess;

/**
 * Připojí PDF soubory za dokument — náhrada PdfCreator::appendFiles
 * ze starého Shipardu. `pdfunite` (poppler-utils) přes dočasné soubory.
 *
 * Params: `pdfs` = list obsahů PDF souborů (strings) v pořadí připojení.
 */
class AppendPdfsStep implements PostProcessStepInterface
{
    /** Test seam pro simulaci chybějícího nástroje (vzor TextExtractor). */
    protected string $pdfuniteBin = 'pdfunite';

    public function apply(string $pdf, array $params): string
    {
        $pdfs = $params['pdfs'] ?? null;
        if (!is_array($pdfs) || $pdfs === []) {
            throw new \InvalidArgumentException("appendPdfs: param 'pdfs' must be a non-empty list of PDF contents");
        }
        foreach ($pdfs as $i => $content) {
            if (!is_string($content) || !str_starts_with($content, '%PDF')) {
                throw new \InvalidArgumentException("appendPdfs: pdfs[{$i}] is not a PDF");
            }
        }

        $tmpDir = sys_get_temp_dir() . '/shpd-render-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true)) {
            throw new \RuntimeException("appendPdfs: cannot create temp dir {$tmpDir}");
        }

        $paths = [];
        try {
            $basePath = $tmpDir . '/000-base.pdf';
            file_put_contents($basePath, $pdf);
            $paths[] = $basePath;
            foreach (array_values($pdfs) as $i => $content) {
                $path = sprintf('%s/%03d-append.pdf', $tmpDir, $i + 1);
                file_put_contents($path, $content);
                $paths[] = $path;
            }
            $outPath = $tmpDir . '/out.pdf';

            $cmd = $this->pdfuniteBin
                . ' ' . implode(' ', array_map('escapeshellarg', $paths))
                . ' ' . escapeshellarg($outPath);
            exec($cmd . ' 2>&1', $output, $exitCode);
            if ($exitCode === 127) {
                throw new \RuntimeException(
                    "appendPdfs: tool '{$this->pdfuniteBin}' is not installed (sudo apt install poppler-utils)",
                );
            }
            if ($exitCode !== 0) {
                throw new \RuntimeException("appendPdfs: {$this->pdfuniteBin} failed (exit {$exitCode})");
            }

            $result = @file_get_contents($outPath);
            if ($result === false || !str_starts_with($result, '%PDF')) {
                throw new \RuntimeException("appendPdfs: {$this->pdfuniteBin} produced no valid PDF");
            }
            $paths[] = $outPath;

            return $result;
        } finally {
            $paths[] = $tmpDir . '/out.pdf';
            foreach (array_unique($paths) as $path) {
                @unlink($path);
            }
            @rmdir($tmpDir);
        }
    }
}
