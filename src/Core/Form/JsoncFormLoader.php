<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Utils\JsoncParser;

class JsoncFormLoader
{
    public function load(string $jsonPath, TableDefinition $tableDef, ?ConfigRuntime $config = null, string $tableId = ''): FormDefinition
    {
        $data = JsoncParser::parseFile($jsonPath);

        $colMap = [];
        foreach ($tableDef->columns as $col) {
            $colMap[$col->id] = $col;
        }

        $tabs = [];
        foreach ($data['tabs'] ?? [] as $tabData) {
            $elements = [];
            foreach ($tabData['elements'] ?? [] as $elData) {
                $elements[] = $this->buildElement($elData, $colMap, $config);
            }
            $tabs[] = new FormTab(
                id: $tabData['id'] ?? '',
                label: $tabData['label'] ?? '',
                elements: $elements,
            );
        }

        $dbName = $tableId !== '' ? $tableId : $tableDef->name;
        return new FormDefinition(
            table: $dbName,
            title: $data['title'] ?? $tableDef->name,
            titleNew: $data['titleNew'] ?? $data['title'] ?? $tableDef->name,
            tabs: $tabs,
            fullSize: $data['fullSize'] ?? false,
        );
    }

    /**
     * @param array<string, ColumnDefinition> $colMap
     */
    private function buildElement(array $elData, array $colMap, ?ConfigRuntime $config): FormElement
    {
        $column = $elData['column'] ?? null;
        $col = $column !== null ? ($colMap[$column] ?? null) : null;

        // Fill missing label from TableDefinition
        $label = $elData['label'] ?? ($col !== null ? $col->name : null);

        // Determine type: explicit in JSONC, or derived from column definition
        $type = $elData['type'] ?? $this->deriveType($col);

        // Resolve select options from config if not provided
        $options = $elData['options'] ?? null;
        if ($type === 'select' && $options === null && $col !== null) {
            $options = $this->resolveEnumOptions($col, $config);
        }

        // Handle nested elements for groups
        $elements = null;
        if (isset($elData['elements']) && is_array($elData['elements'])) {
            $elements = array_map(
                fn(array $nested) => $this->buildElement($nested, $colMap, $config),
                $elData['elements'],
            );
        }

        // Derive inputType from JSONC or column definition
        $inputType = $elData['inputType'] ?? null;
        if ($inputType === null && $type === 'input' && $col !== null) {
            $inputType = $this->deriveInputType($col);
        }

        return new FormElement(
            type: $type,
            cols: $elData['cols'] ?? 1,
            column: $column,
            label: $label,
            placeholder: $elData['placeholder'] ?? null,
            required: $elData['required'] ?? ($col !== null && !$col->nullable && $col->default === null),
            readOnly: $elData['readOnly'] ?? false,
            hidden: $elData['hidden'] ?? false,
            triggers: $elData['triggers'] ?? null,
            hint: $elData['hint'] ?? null,
            options: $options,
            elements: $elements,
            table: $elData['table'] ?? null,
            foreignKey: $elData['foreignKey'] ?? null,
            formId: $elData['formId'] ?? null,
            content: $elData['content'] ?? null,
            inputType: $inputType,
        );
    }

    private function deriveType(?ColumnDefinition $col): string
    {
        if ($col === null) {
            return 'input';
        }
        if (in_array($col->type, ['enumInt', 'enumString'], true)) {
            return 'select';
        }
        return 'input';
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

    private function resolveEnumOptions(ColumnDefinition $col, ?ConfigRuntime $config): ?array
    {
        if ($config === null || $col->cfgItem === null) {
            return null;
        }

        $cfgData = $config->cfgItem($col->cfgItem);
        if (!is_array($cfgData)) {
            return null;
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
