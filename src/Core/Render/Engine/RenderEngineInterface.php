<?php

declare(strict_types=1);

namespace Shipard\Core\Render\Engine;

use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderResult;

/**
 * Kontrakt engine adaptéru (D3) — jediné místo, kde se mluví s konkrétní
 * rendering technologií. Budoucí enginy (Apache FOP) = další implementace.
 *
 * Obsah se vždy pushuje v requestu (D4) — engine nikdy nedostane URL.
 * Okraje v PdfOptions už mají doplněné defaulty profilu (withDefaults
 * aplikuje RenderClient).
 */
interface RenderEngineInterface
{
    /** @param array<string, string> $assets mapa filename => content */
    public function renderHtml(string $html, array $assets, PdfOptions $options, int $timeoutSec): RenderResult;

    public function convertOffice(string $fileName, string $content, int $timeoutSec): RenderResult;

    /**
     * Vloží soubory do PDF jako attachmenty (post-processing embedIsdoc).
     *
     * @param list<array{fileName: string, content: string}> $attachments
     */
    public function embedFiles(string $pdfContent, array $attachments, int $timeoutSec): RenderResult;

    /** Health check služby — krátký timeout, používá doctor. */
    public function health(): bool;
}
