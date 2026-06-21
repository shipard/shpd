<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Báze pro documentEventHandlers — stejná trojice služeb a setterů jako
 * Document, injektuje je DocumentEventDispatcher při instanciaci.
 */
abstract class AbstractDocumentEventHandler implements DocumentEventHandler
{
    protected ?\Dibi\Connection $db = null;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConfig $dsConfig = null;

    /**
     * Journal dispatcher pro handlery, které konstruují účtovací engine
     * (DocsHeadsEventHandler, BankTransactionEventHandler) — engine ho použije
     * k vyslání journalWritten. Injektuje DocumentEventDispatcher.
     */
    protected ?JournalEventDispatcher $journalEvents = null;

    public function setDb(\Dibi\Connection $db): void
    {
        $this->db = $db;
    }

    public function setJournalEvents(?JournalEventDispatcher $journalEvents): void
    {
        $this->journalEvents = $journalEvents;
    }

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
    }

    public function onBeforeDelete(string $tableId, array $data): void
    {
    }
}
