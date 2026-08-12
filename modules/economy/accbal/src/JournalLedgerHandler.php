<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Settings\SettingsStore;

/**
 * Handler události journalWritten — re-deriduje saldo pohyby zdroje z deníku
 * (LedgerGenerator). Registrace v module.jsonc → journalEventHandlers.
 *
 * Výjimku spolkne JournalEventDispatcher (commit deníku už proběhl, saldo
 * neblokuje účtování); stálé saldo se dořeší re-derivací / alertem.
 */
final class JournalLedgerHandler extends AbstractJournalEventHandler
{
    /** Per-instance cache — dispatcher instance memoizuje, viz homeCurrency(). */
    private ?string $homeCurrency = null;

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        if ($this->db === null) {
            return;
        }
        (new LedgerGenerator($this->db, $this->config, $this->homeCurrency()))
            ->generate($sourceKind, $sourceId);
    }

    /**
     * Domácí měna ze settings `economy.homeCurrency` (ds-setup.md §5.2),
     * nerozhodnutý klíč → 'czk'. JournalEventDispatcher instance handlerů
     * memoizuje, takže dávkové přeúčtování = jeden dotaz, ne dotaz na doklad.
     */
    private function homeCurrency(): string
    {
        if ($this->homeCurrency === null) {
            $value = $this->db !== null
                ? (new SettingsStore(new DataSourceConnection($this->db)))->get('economy.homeCurrency')
                : null;
            $this->homeCurrency = is_string($value) && $value !== '' ? $value : 'czk';
        }
        return $this->homeCurrency;
    }
}
