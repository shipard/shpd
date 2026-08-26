<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Export entit DS do výměnného formátu (opačný směr k applierům).
 *
 * `exportAll()` bere celý DS (všechny záznamy s `docState != 90`),
 * `exportByIds()` jen vybrané — CLI filtr nevystavuje, ale round-trip
 * integrační test na sdíleném DS ho potřebuje (R7 v tasks/dataset-phase1.md).
 * Obě metody vracejí záznamy v deterministickém pořadí.
 */
interface RecordExporter
{
    /** Název sekce sady (`persons`, `items`, `docs`, `registry`, `mail`). */
    public function section(): string;

    /** @return list<ExportedRecord> */
    public function exportAll(): array;

    /**
     * @param list<int> $ids
     * @return list<ExportedRecord>
     */
    public function exportByIds(array $ids): array;

    /**
     * Věci, které formát nenese a při exportu se ztratily (např. řádkový
     * partner účetního dokladu). CLI je vypíše.
     *
     * @return list<string>
     */
    public function getWarnings(): array;
}
