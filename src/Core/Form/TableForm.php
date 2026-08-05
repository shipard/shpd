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

    /**
     * Server-side defaulty nového záznamu odvozené z client prefillu
     * (`?defaults[...]`) nebo kontextu (např. default pohyb řádku podle
     * doc_type hlavičky z `defaults[doc_head]`). Volá FormController
     * (GET /meta bez id) před buildFormDefinition; mutace `$data` se
     * propíší do response `data` — na rozdíl od mutací uvnitř
     * buildFormDefinition, které ovlivní jen podmíněné renderování.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
    }

    /**
     * Volitelná strukturovaná hlavička formuláře pro existující záznam.
     *
     * Default: žádná hlavička (modal zobrazí jen `title` z FormDefinition).
     * Subclassy mohou přepsat a vrátit `FormHeaderInfo` s identifikačními údaji
     * (např. název firmy, IČO, kód).
     *
     * Tato metoda se volá v `FormController` pro `GET /meta/{id}` a po úspěšném
     * `save` — NE pro `GET /meta` (nový záznam) ani pro `recalculate`. Hodnoty
     * v `$data` jsou tedy data uložená v DB, ne živá data z formuláře.
     */
    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        return null;
    }

    /**
     * Opt-in whitelist sensitive sloupců editovatelných tímto formem.
     *
     * TableAccessGuard normálně odmítá zápis sensitive sloupců přes
     * form save (400 SENSITIVE_COLUMN) — sloupce vyjmenované zde projdou.
     * Konvence pro takové pole: input startuje prázdný (data ho nikdy
     * neobsahují — stripSensitive), placeholder `●●●●●● (zadat pro
     * změnu)`, prázdný submit hodnotu nemění (Document beforeSave
     * prázdnou hodnotu unsetne — vzor HostingDataSourceDocument).
     * Šifrování/uložení zůstává odpovědností Document třídy.
     *
     * @return list<string>
     */
    public function getEditableSensitiveColumns(): array
    {
        return [];
    }

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
                $colLabels[$col->id] = $col->formLabel ?? $col->name;
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
    protected function attachmentsTab(
        string $id = 'attachments',
        string $label = 'Přílohy',
        ?string $changeEndpoint = null,
    ): FormTab {
        $tableId = $this->tableDef?->tableId ?? 0;
        return new FormTab(
            id: $id,
            label: $label,
            type: 'attachments',
            tableId: $tableId,
            changeEndpoint: $changeEndpoint,
        );
    }
}
