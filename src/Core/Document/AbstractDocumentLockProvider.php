<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Báze pro documentLockProviders — stejná trojice služeb a setterů jako
 * Document a AbstractDocumentEventHandler, injektuje je DocumentLockRegistry
 * při instanciaci. Bez DB provider typicky vrací prázdno (viz konkrétní
 * implementace) — o fail-closed se stará to, že registry bez DB nikdo
 * nestaví v zápisové cestě.
 */
abstract class AbstractDocumentLockProvider implements DocumentLockProvider
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
}
