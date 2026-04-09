<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;

class AutoFormBuilder
{
    private const SKIP_COLUMNS = ['id', 'created', 'modified'];

    public function build(TableDefinition $tableDef, ?ConfigRuntime $config = null, string $tableId = ''): FormDefinition
    {
        // Group columns by their group ID
        $grouped = [];
        foreach ($tableDef->columns as $col) {
            if ($this->shouldSkip($col)) {
                continue;
            }
            $groupId = $col->group ?? '__general__';
            $grouped[$groupId][] = $col;
        }

        // Build group label map from columnGroups
        $groupLabels = [];
        $groupOrder = [];
        foreach ($tableDef->columnGroups as $group) {
            $groupLabels[$group['id']] = $group['name'] ?? $group['id'];
            $groupOrder[] = $group['id'];
        }

        // Build tabs: general first (if exists), then in columnGroups order
        $tabs = [];

        if (isset($grouped['__general__'])) {
            $tabs[] = $this->buildTab('general', 'Obecné', $grouped['__general__'], $config);
        }

        foreach ($groupOrder as $groupId) {
            if (isset($grouped[$groupId])) {
                $label = $groupLabels[$groupId] ?? $groupId;
                $tabs[] = $this->buildTab($groupId, $label, $grouped[$groupId], $config);
            }
        }

        // Any remaining groups not in columnGroups definition
        foreach ($grouped as $groupId => $columns) {
            if ($groupId === '__general__' || isset($groupLabels[$groupId])) {
                continue;
            }
            $tabs[] = $this->buildTab($groupId, $groupId, $columns, $config);
        }

        $dbName = $tableId !== '' ? $tableId : $tableDef->name;
        return new FormDefinition(
            table: $dbName,
            title: $tableDef->name,
            titleNew: $tableDef->name,
            tabs: $tabs,
        );
    }

    /** @param ColumnDefinition[] $columns */
    private function buildTab(string $id, string $label, array $columns, ?ConfigRuntime $config): FormTab
    {
        $elements = [];
        foreach ($columns as $col) {
            $elements[] = $this->buildElement($col, $config);
        }
        return new FormTab($id, $label, $elements);
    }

    private function buildElement(ColumnDefinition $col, ?ConfigRuntime $config): FormElement
    {
        $isEnum = in_array($col->type, ['enumInt', 'enumString'], true);

        if ($isEnum) {
            return new FormElement(
                type: 'select',
                cols: $this->determineCols($col),
                column: $col->id,
                label: $col->name,
                required: $this->isRequired($col),
                options: $this->resolveEnumOptions($col, $config),
            );
        }

        return new FormElement(
            type: 'input',
            cols: $this->determineCols($col),
            column: $col->id,
            label: $col->name,
            required: $this->isRequired($col),
            inputType: $this->deriveInputType($col),
        );
    }

    private function determineCols(ColumnDefinition $col): int
    {
        if (in_array($col->type, ['text', 'longtext'], true)) {
            return 4;
        }
        if ($col->type === 'varchar' && $col->length !== null && $col->length > 30) {
            return 2;
        }
        return 1;
    }

    private function isRequired(ColumnDefinition $col): bool
    {
        return !$col->nullable && $col->default === null;
    }

    private function shouldSkip(ColumnDefinition $col): bool
    {
        if ($col->system) {
            return true;
        }
        if (in_array($col->id, self::SKIP_COLUMNS, true)) {
            return true;
        }
        if (str_contains($col->id, 'password')) {
            return true;
        }
        return false;
    }

    private function deriveInputType(ColumnDefinition $col): string
    {
        return match ($col->type) {
            'boolean'                                              => 'checkbox',
            'date'                                                 => 'date',
            'datetime'                                             => 'datetime',
            'time'                                                 => 'time',
            'text', 'longtext'                                     => 'textarea',
            'int', 'smallint', 'bigint', 'tinyint', 'numeric', 'float' => 'number',
            default                                                => 'text',
        };
    }

    private function resolveEnumOptions(ColumnDefinition $col, ?ConfigRuntime $config): array
    {
        if ($config === null || $col->cfgItem === null) {
            return [];
        }

        $cfgData = $config->cfgItem($col->cfgItem);
        if (!is_array($cfgData)) {
            return [];
        }

        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $value = $col->type === 'enumInt' ? (int) $key : (string) $key;
                $options[] = ['value' => $value, 'label' => $entry['name']];
            }
        }

        return $options;
    }
}
