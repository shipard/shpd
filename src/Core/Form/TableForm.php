<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

abstract class TableForm
{
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;
    protected ?TableDefinition $tableDef = null;

    public function __construct(
        protected string $table,
    ) {}

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDb(DataSourceConnection $db): void
    {
        $this->db = $db;
    }

    public function setTableDef(TableDefinition $tableDef): void
    {
        $this->tableDef = $tableDef;
    }

    abstract public function buildFormDefinition(array $data, bool $isNew): FormDefinition;

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    protected function tab(string $id, string $label): TabBuilder
    {
        // Sestav mapu column_id => label z TableDefinition pro auto-label
        $colLabels = [];
        if ($this->tableDef !== null) {
            foreach ($this->tableDef->columns as $col) {
                $colLabels[$col->id] = $col->name;
            }
        }
        return new TabBuilder($id, $label, $colLabels);
    }
}
