<?php

declare(strict_types=1);

namespace Shipard\Core\Render\PostProcess;

/**
 * Krok post-processing řetězu nad vráceným PDF (D8). Běží vždy u nás,
 * ne v render kontejneru — budoucí kroky (el. podpis) nesmí pouštět
 * klíče mimo kontejner zákazníka.
 *
 * Selhání = výjimka; RenderClient::postProcess ji mapuje na
 * RenderResult (InvalidArgumentException → invalidInput, ostatní →
 * engineError s poznámkou kroku).
 */
interface PostProcessStepInterface
{
    /**
     * @param array<string, mixed> $params
     * @return string nové PDF
     */
    public function apply(string $pdf, array $params): string;
}
