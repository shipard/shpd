<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Binární příloha exportovaného záznamu (spisovna, došlá pošta).
 *
 * `name` je jméno souboru uvnitř sidecar složky záznamu
 * (`<sekce>/NNNN-<slug>.files/<name>`) — exporter ho drží unikátní v rámci
 * záznamu a totéž jméno zapisuje do `attachments[].file` v datech záznamu.
 * `sourcePath` je absolutní cesta v `att/` zdrojového DS.
 */
final class ExportedFile
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $name,
        public readonly int $attachmentId,
    ) {}
}
