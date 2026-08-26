<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Jeden záznam vyexportovaný do sady: canonical data + návrh slugu pro
 * název souboru + přílohy k překopírování do sidecar složky.
 *
 * Pořadové číslo v názvu souboru přiděluje až `dataset-dump` podle pořadí,
 * v jakém exporter záznamy vrátil (exportery řadí podle přirozených klíčů,
 * ne podle interních id — determinismus napříč DS).
 */
final class ExportedRecord
{
    /**
     * @param array<string, mixed> $data
     * @param list<ExportedFile>   $files
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly array $data,
        public readonly array $files = [],
    ) {}
}
