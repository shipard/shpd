<?php

declare(strict_types=1);

namespace Shipard\Core\Render\PostProcess;

use Shipard\Core\Render\Engine\RenderEngineInterface;

/**
 * Vloží soubor (typicky ISDOC) do PDF jako attachment. Primárně přes
 * Gotenberg routu `forms/pdfengines/embed`; když engine chybí nebo
 * selže, fallback `pdfattach` (poppler-utils) — výsledek je v obou
 * případech běžný PDF attachment ověřitelný `pdfdetach -list`.
 *
 * Params: `fileName` (název attachmentu), `content` (obsah souboru).
 */
class EmbedIsdocStep implements PostProcessStepInterface
{
    /** Test seam pro simulaci chybějícího nástroje (vzor TextExtractor). */
    protected string $pdfattachBin = 'pdfattach';

    public function __construct(
        private readonly ?RenderEngineInterface $engine = null,
        private readonly int $timeoutSec = 30,
    ) {
    }

    public function apply(string $pdf, array $params): string
    {
        $fileName = $params['fileName'] ?? '';
        $content = $params['content'] ?? '';
        if (!is_string($fileName) || !is_string($content) || $fileName === '' || $content === '') {
            throw new \InvalidArgumentException("embedIsdoc: params 'fileName' and 'content' are required");
        }
        $safeName = str_replace(["\r", "\n", '"'], '', basename($fileName));

        if ($this->engine !== null) {
            $result = $this->engine->embedFiles(
                $pdf,
                [['fileName' => $safeName, 'content' => $content]],
                $this->timeoutSec,
            );
            if ($result->ok && $result->pdfContent !== null) {
                return $result->pdfContent;
            }
            // Engine selhal → fallback pdfattach; důvod se neztratí,
            // pdfattach buď uspěje, nebo vyhodí vlastní výjimku.
        }

        return $this->embedWithPdfattach($pdf, $safeName, $content);
    }

    private function embedWithPdfattach(string $pdf, string $safeName, string $content): string
    {
        $tmpDir = sys_get_temp_dir() . '/shpd-render-' . bin2hex(random_bytes(6));
        $attDir = $tmpDir . '/att';
        if (!@mkdir($attDir, 0700, true)) {
            throw new \RuntimeException("embedIsdoc: cannot create temp dir {$tmpDir}");
        }

        $inPath = $tmpDir . '/in.pdf';
        // pdfattach pojmenuje attachment podle basename souboru na disku
        $attPath = $attDir . '/' . $safeName;
        $outPath = $tmpDir . '/out.pdf';

        try {
            file_put_contents($inPath, $pdf);
            file_put_contents($attPath, $content);

            $cmd = sprintf(
                '%s %s %s %s',
                $this->pdfattachBin,
                escapeshellarg($inPath),
                escapeshellarg($attPath),
                escapeshellarg($outPath),
            );
            exec($cmd . ' 2>&1', $output, $exitCode);
            if ($exitCode === 127) {
                throw new \RuntimeException(
                    "embedIsdoc: tool '{$this->pdfattachBin}' is not installed (sudo apt install poppler-utils)",
                );
            }
            if ($exitCode !== 0) {
                throw new \RuntimeException("embedIsdoc: {$this->pdfattachBin} failed (exit {$exitCode})");
            }

            $result = @file_get_contents($outPath);
            if ($result === false || !str_starts_with($result, '%PDF')) {
                throw new \RuntimeException("embedIsdoc: {$this->pdfattachBin} produced no valid PDF");
            }

            return $result;
        } finally {
            foreach ([$inPath, $attPath, $outPath] as $path) {
                @unlink($path);
            }
            @rmdir($attDir);
            @rmdir($tmpDir);
        }
    }
}
