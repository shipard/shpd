<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class SchemaValidator
{
    /**
     * @param array[] $tableDefinitions  raw parsed arrays (not TableDefinition objects)
     * @return array{errors: string[], warnings: string[]}
     */
    public static function validate(array $tableDefinitions): array
    {
        $errors = [];
        $warnings = [];

        // Cross-table: duplicate tableId
        $seenTableIds = [];
        foreach ($tableDefinitions as $table) {
            $tableName = $table['name'] ?? '(unknown)';

            if (!isset($table['tableId'])) {
                $errors[] = "Table '{$tableName}' is missing required field: tableId";
                continue;
            }

            $tableId = $table['tableId'];
            if (isset($seenTableIds[$tableId])) {
                $errors[] = "Duplicate tableId {$tableId}: tables {$seenTableIds[$tableId]} and {$tableName}";
            } else {
                $seenTableIds[$tableId] = $tableName;
            }
        }

        // Per-table validations
        foreach ($tableDefinitions as $table) {
            $tableName = $table['name'] ?? '(unknown)';
            $columns = $table['columns'] ?? [];
            $indexes = $table['indexes'] ?? [];
            $columnGroups = $table['columnGroups'] ?? [];

            // Missing primary key
            $hasPk = false;
            foreach ($columns as $col) {
                if (!empty($col['primaryKey'])) {
                    $hasPk = true;
                    break;
                }
            }
            if (!$hasPk) {
                $errors[] = "Table '{$tableName}' has no primary key column";
            }

            // Duplicate column ids
            $seenCols = [];
            foreach ($columns as $col) {
                $colId = $col['id'] ?? null;
                if ($colId === null) {
                    continue;
                }
                if (isset($seenCols[$colId])) {
                    $errors[] = "Duplicate column id '{$colId}' in table '{$tableName}'";
                } else {
                    $seenCols[$colId] = true;
                }
            }

            // Duplicate index ids
            $seenIdxs = [];
            foreach ($indexes as $idx) {
                $idxId = $idx['id'] ?? null;
                if ($idxId === null) {
                    continue;
                }
                if (isset($seenIdxs[$idxId])) {
                    $errors[] = "Duplicate index id '{$idxId}' in table '{$tableName}'";
                } else {
                    $seenIdxs[$idxId] = true;
                }
            }

            // Unused column groups
            $referencedGroups = [];
            foreach ($columns as $col) {
                if (isset($col['group'])) {
                    $referencedGroups[$col['group']] = true;
                }
            }
            foreach ($columnGroups as $group) {
                $groupId = $group['id'] ?? null;
                if ($groupId !== null && !isset($referencedGroups[$groupId])) {
                    $warnings[] = "Column group '{$groupId}' in table '{$tableName}' has no columns assigned";
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
