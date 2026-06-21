<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Document\AbstractJournalEventHandler;

/**
 * Handler události journalWritten — re-deriduje saldo pohyby zdroje z deníku
 * (LedgerGenerator). Registrace v module.jsonc → journalEventHandlers.
 *
 * Výjimku spolkne JournalEventDispatcher (commit deníku už proběhl, saldo
 * neblokuje účtování); stálé saldo se dořeší re-derivací / alertem.
 */
final class JournalLedgerHandler extends AbstractJournalEventHandler
{
    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        if ($this->db === null) {
            return;
        }
        (new LedgerGenerator($this->db, $this->config, $this->dsConfig))
            ->generate($sourceKind, $sourceId);
    }
}
