<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Báze pro journalEventHandlers — stejná trojice služeb a setterů jako
 * AbstractDocumentEventHandler, injektuje je JournalEventDispatcher při
 * instanciaci.
 */
abstract class AbstractJournalEventHandler implements JournalEventHandler
{
    protected ?\Dibi\Connection $db = null;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConfig $dsConfig = null;

    public function setDb(\Dibi\Connection $db): void
    {
        $this->db = $db;
    }

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
    }
}
