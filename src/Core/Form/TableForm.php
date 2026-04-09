<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

abstract class TableForm
{
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;

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
        return new TabBuilder($id, $label);
    }
}
