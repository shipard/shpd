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

    protected function tab(string $id, string $label, ?string $icon = null): TabBuilder
    {
        $colLabels = [];
        if ($this->tableDef !== null) {
            foreach ($this->tableDef->columns as $col) {
                $colLabels[$col->id] = $col->name;
            }
        }
        return new TabBuilder($id, $label, $colLabels, $icon);
    }

    /**
     * Build a tab that hosts a child table. The child table is rendered as a
     * grid with its own toolbar; the parent record is identified by parentId
     * at runtime.
     */
    protected function subtableTab(
        string $id,
        string $label,
        string $table,
        string $foreignKey,
        ?string $formId = null,
        ?string $sort = null,
        ?string $icon = null,
    ): FormTab {
        return new FormTab(
            id: $id,
            label: $label,
            type: 'subtable',
            subtable: [
                'table'      => $table,
                'foreignKey' => $foreignKey,
                'formId'     => $formId,
                'sort'       => $sort,
            ],
            icon: $icon,
        );
    }

    /**
     * Localized label for the generic "General" tab — same source as
     * AutoFormBuilder, so every form (PHP / JSONC / auto) shows the same label.
     */
    protected function defaultGeneralTabLabel(): string
    {
        $defaults = $this->config?->cfgItem('core.system.formDefaults');
        return $defaults['generalTabLabel']['name'] ?? 'General';
    }

    /**
     * Create an attachments tab for the current table. The tableId is taken
     * from the loaded table definition.
     */
    protected function attachmentsTab(string $id = 'attachments', string $label = 'Přílohy'): FormTab
    {
        $tableId = $this->tableDef?->tableId ?? 0;
        return new FormTab(
            id: $id,
            label: $label,
            type: 'attachments',
            tableId: $tableId,
        );
    }
}
