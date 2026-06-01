<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;

/**
 * Loads a JSONC form definition into the new section/column wire model.
 *
 * Expected source shape (camelCase keys for hand-authored fields):
 *   {
 *     "title": "...", "titleNew": "...",
 *     "tabs": [
 *       { "id": "...", "label": "...", "type": "fields" /* default *\/,
 *         "sections": [
 *           { "title": null, "hidden": false,
 *             "columns": [
 *               { "elements": [ {"type": "input", "column": "...", ...} ] }
 *             ]
 *           }
 *         ]
 *       },
 *       { "id": "...", "label": "...", "type": "subtable",
 *         "subtable": {"table": "...", "foreignKey": "...", "formId": "..."} },
 *       { "id": "...", "label": "...", "type": "attachments", "tableId": 110 }
 *     ]
 *   }
 *
 * The old format (tabs with `elements` directly, `cols` on element, `group` type) is
 * actively rejected with a clear error to surface forgotten migrations.
 */
class JsoncFormLoader
{
    public function load(
        string $jsonPath,
        TableDefinition $tableDef,
        ?ConfigRuntime $config = null,
        string $tableId = '',
        string $language = 'en',
    ): FormDefinition {
        $data = JsoncParser::parseFile($jsonPath);
        $data = ConfigLocalizer::localize($data, $language);

        $colMap = [];
        foreach ($tableDef->columns as $col) {
            $colMap[$col->id] = $col;
        }

        $tabs = [];
        foreach (($data['tabs'] ?? []) as $i => $tabData) {
            $tabs[] = $this->buildTab($tabData, $i, $colMap, $config, $jsonPath);
        }

        $dbName = $tableId !== '' ? $tableId : $tableDef->name;
        return new FormDefinition(
            table: $dbName,
            title: $data['title'] ?? $tableDef->name,
            titleNew: $data['titleNew'] ?? $data['title'] ?? $tableDef->name,
            tabs: $tabs,
        );
    }

    /**
     * @param array<string, ColumnDefinition> $colMap
     */
    private function buildTab(array $tabData, int $idx, array $colMap, ?ConfigRuntime $config, string $jsonPath): FormTab
    {
        $id    = $tabData['id'] ?? '';
        $label = $tabData['label'] ?? '';
        $type  = $tabData['type'] ?? 'fields';

        // Detect old format at the tab level: elements[] directly inside a tab without
        // 'sections' is the legacy shape; bail out with a clear error.
        if (isset($tabData['elements']) && !isset($tabData['sections']) && $type === 'fields') {
            throw new \RuntimeException(sprintf(
                'JsoncFormLoader: %s tab "%s" uses the legacy "elements[]" shape. '
                . 'The form must be ported to the new sections[]→columns[]→elements[] model.',
                $jsonPath, $id,
            ));
        }

        if ($type === 'subtable') {
            $sub = $tabData['subtable'] ?? null;
            if (!is_array($sub) || !isset($sub['table'], $sub['foreignKey'])) {
                throw new \RuntimeException(sprintf(
                    'JsoncFormLoader: %s tab "%s" of type "subtable" requires subtable {table, foreignKey, [formId]}',
                    $jsonPath, $id,
                ));
            }
            return new FormTab(
                id: $id,
                label: $label,
                type: 'subtable',
                subtable: [
                    'table'      => $sub['table'],
                    'foreignKey' => $sub['foreignKey'],
                    'formId'     => $sub['formId'] ?? null,
                    'sort'       => $sub['sort'] ?? null,
                ],
                icon: $tabData['icon'] ?? null,
            );
        }

        if ($type === 'attachments') {
            $tableId = $tabData['tableId'] ?? null;
            if (!is_int($tableId)) {
                throw new \RuntimeException(sprintf(
                    'JsoncFormLoader: %s tab "%s" of type "attachments" requires integer tableId',
                    $jsonPath, $id,
                ));
            }
            return new FormTab(
                id: $id,
                label: $label,
                type: 'attachments',
                tableId: $tableId,
                icon: $tabData['icon'] ?? null,
            );
        }

        // type='fields'
        $sections = [];
        foreach (($tabData['sections'] ?? []) as $sIdx => $sectionData) {
            $sections[] = $this->buildSection($sectionData, $id, $sIdx, $colMap, $config, $jsonPath);
        }

        if ($sections === []) {
            throw new \RuntimeException(sprintf(
                'JsoncFormLoader: %s tab "%s" of type "fields" has no sections (sections[] is required and non-empty)',
                $jsonPath, $id,
            ));
        }

        return new FormTab(
            id: $id,
            label: $label,
            sections: $sections,
            type: 'fields',
            icon: $tabData['icon'] ?? null,
        );
    }

    /**
     * @param array<string, ColumnDefinition> $colMap
     */
    private function buildSection(array $sectionData, string $tabId, int $sIdx, array $colMap, ?ConfigRuntime $config, string $jsonPath): FormSection
    {
        $columnsData = $sectionData['columns'] ?? null;
        if (!is_array($columnsData) || $columnsData === []) {
            throw new \RuntimeException(sprintf(
                'JsoncFormLoader: %s tab "%s" section[%d] requires non-empty columns[]',
                $jsonPath, $tabId, $sIdx,
            ));
        }

        $columns = [];
        foreach ($columnsData as $cIdx => $colData) {
            $elementsData = $colData['elements'] ?? null;
            if (!is_array($elementsData)) {
                throw new \RuntimeException(sprintf(
                    'JsoncFormLoader: %s tab "%s" section[%d] column[%d] requires elements[]',
                    $jsonPath, $tabId, $sIdx, $cIdx,
                ));
            }
            $elements = [];
            foreach ($elementsData as $elData) {
                $elements[] = $this->buildElement($elData, $colMap, $config, $jsonPath);
            }
            $columns[] = new FormColumn($elements);
        }

        return new FormSection(
            columns: $columns,
            title: $sectionData['title'] ?? null,
            hidden: $sectionData['hidden'] ?? false,
        );
    }

    /**
     * @param array<string, ColumnDefinition> $colMap
     */
    private function buildElement(array $elData, array $colMap, ?ConfigRuntime $config, string $jsonPath): FormElement
    {
        // Reject the legacy `cols` field — it signals an unported element.
        if (array_key_exists('cols', $elData)) {
            throw new \RuntimeException(sprintf(
                'JsoncFormLoader: %s — element "%s" still has legacy "cols" key. '
                . 'Element widths are determined by the parent section\'s columns now.',
                $jsonPath, $elData['column'] ?? ($elData['type'] ?? '?'),
            ));
        }

        $type = $elData['type'] ?? null;
        if ($type === 'group' || $type === 'subtable') {
            throw new \RuntimeException(sprintf(
                'JsoncFormLoader: %s — element type "%s" is no longer supported. '
                . 'Use inline groups or convert subtables to dedicated tabs.',
                $jsonPath, $type,
            ));
        }

        $column = $elData['column'] ?? null;
        $col = $column !== null ? ($colMap[$column] ?? null) : null;

        // Fill missing label from TableDefinition.
        $label = $elData['label'] ?? ($col !== null ? $col->name : null);

        // Determine type: explicit in JSONC, or derived from column definition.
        $type = $type ?? $this->deriveType($col);

        // Resolve select options from config if not provided.
        $options = $elData['options'] ?? null;
        if ($type === 'select' && $options === null && $col !== null) {
            $options = $this->resolveEnumOptions($col, $config);
        }

        // Nested elements (inline groups).
        $elements = null;
        if (isset($elData['elements']) && is_array($elData['elements'])) {
            $elements = array_map(
                fn(array $nested) => $this->buildElement($nested, $colMap, $config, $jsonPath),
                $elData['elements'],
            );
        }

        // Derive inputType from JSONC or column definition.
        $inputType = $elData['inputType'] ?? null;
        if ($inputType === null && $type === 'input' && $col !== null) {
            $inputType = $this->deriveInputType($col);
        }

        // Lookup config — only meaningful for type=lookup; FormElement validates the rest.
        $lookup = null;
        if ($type === 'lookup') {
            $rawLookup = $elData['lookup'] ?? null;
            if (!is_array($rawLookup) || !isset($rawLookup['table']) || !is_string($rawLookup['table']) || $rawLookup['table'] === '') {
                throw new \RuntimeException(sprintf(
                    'JsoncFormLoader: %s — lookup element "%s" requires lookup.table',
                    $jsonPath, $column ?? '?',
                ));
            }
            $filter = $rawLookup['filter'] ?? null;
            if ($filter !== null && !is_array($filter)) {
                throw new \RuntimeException(sprintf(
                    'JsoncFormLoader: %s — lookup element "%s" filter must be object|null',
                    $jsonPath, $column ?? '?',
                ));
            }
            $lookup = [
                'table'  => $rawLookup['table'],
                'filter' => $filter,
            ];
            // editForm / createForm (camelCase v JSONC) → edit_form / create_form na drátě
            if (array_key_exists('editForm', $rawLookup)) {
                if (!is_bool($rawLookup['editForm'])) {
                    throw new \RuntimeException(sprintf(
                        'JsoncFormLoader: %s — lookup element "%s" editForm must be boolean',
                        $jsonPath, $column ?? '?',
                    ));
                }
                $lookup['edit_form'] = $rawLookup['editForm'];
            }
            if (array_key_exists('createForm', $rawLookup)) {
                if (!is_bool($rawLookup['createForm'])) {
                    throw new \RuntimeException(sprintf(
                        'JsoncFormLoader: %s — lookup element "%s" createForm must be boolean',
                        $jsonPath, $column ?? '?',
                    ));
                }
                $lookup['create_form'] = $rawLookup['createForm'];
            }
            if (array_key_exists('editTriggers', $rawLookup)) {
                if (!is_bool($rawLookup['editTriggers'])) {
                    throw new \RuntimeException(sprintf(
                        'JsoncFormLoader: %s — lookup element "%s" editTriggers must be boolean',
                        $jsonPath, $column ?? '?',
                    ));
                }
                $lookup['edit_triggers'] = $rawLookup['editTriggers'];
            }
        }

        return new FormElement(
            type: $type,
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
            content: $elData['content'] ?? null,
            componentName: $elData['componentName'] ?? null,
            inputType: $inputType,
            lookup: $lookup,
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
            'boolean'                                                  => 'checkbox',
            'date'                                                     => 'date',
            'datetime'                                                 => 'datetime',
            'time'                                                     => 'time',
            'text', 'longtext'                                         => 'textarea',
            'int', 'smallint', 'bigint', 'tinyint', 'numeric', 'float' => 'number',
            default                                                    => 'text',
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
