<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

/**
 * Provider zámku záznamu (#55 D24) — registrace v module.jsonc sekci
 * `documentLockProviders: [{table, class}]`, sbírá DocumentLoader do
 * DocumentRegistry, volá DocumentLockRegistry.
 *
 * Provider rozhoduje sám, co je pro něj „obsah" záznamu: DPH provider kouká
 * na rekapitulaci a ukazatele instancí, měsíční na `fiscal_month`. Jádro
 * (`docs.core`) o DPH ani fiskálních měsících neví.
 *
 * Kdo se ptá:
 *  - TableGateway::saveDocument — po `Document::validate`, před `beforeSave`;
 *    `$data` = nový stav (s injektovaným efektivním docState), `$original` =
 *    uložený řádek s child sety, null u insertu. Každý důvod = chyba
 *    formuláře s kódem `locked`.
 *  - TableGateway::deleteDocument, CrudController (generické REST),
 *    DocStateTransitionFilter (nabídka přechodů), UI meta (`lock` blok) —
 *    nad uloženým řádkem, tj. `$data === $original`.
 *
 * Výjimka providera se **propaguje** a zápis selže — zámek je fail-closed.
 */
interface DocumentLockProvider
{
    /**
     * Důvody, proč záznam nejde uložit / změnit stav / smazat. Prázdné = volný.
     *
     * @param array<string, mixed> $data nový stav záznamu
     * @param array<string, mixed>|null $original uložený řádek (null u insertu)
     * @return list<DocumentLockReason>
     */
    public function lockReasons(string $tableId, array $data, ?array $original): array;
}
