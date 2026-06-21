<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

/**
 * Hook na zápis účetního deníku pro cizí moduly — modul (saldo) reaguje na
 * každý (pře)zápis nebo vymazání deníku zdroje, aniž by účtovací engine
 * závisel na něm. Fire-point je **engine** (ne TableGateway/stateChanged),
 * aby i přeúčtování (reaccount) bez změny docState událost vyslalo.
 *
 * Registrace v `module.jsonc` → `journalEventHandlers: [{class, events}]`
 * (bez `table` — journal události nejsou per-tabulka), dispatch zajišťuje
 * JournalEventDispatcher. Konkrétní handlery dědí z AbstractJournalEventHandler.
 */
interface JournalEventHandler
{
    /**
     * Po commitu (pře)zápisu nebo vymazání deníku zdroje. Výjimku dispatcher
     * zaloguje a spolkne — commit deníku už proběhl a nesmí selhat kvůli
     * handleru (filozofie „účtování/saldo se neblokují").
     *
     * @param string $sourceKind shodný s economy_accounting_journal.source_kind
     *                           (`doc` | `bankTransaction`)
     * @param int    $sourceId   id zdroje (doc_head / bank_transaction)
     */
    public function onJournalWritten(string $sourceKind, int $sourceId): void;
}
