<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class SchemaComparator
{
    /**
     * @param array<string, string> $existingColumns     col_name → SQL type string (e.g. 'varchar(100)', 'int(11)')
     * @param string[]              $existingIndexes      list of existing index names
     * @param array<string, bool>   $existingNullability  col_name → nullable; empty = nullability se neporovnává
     * @return array[]  list of operation arrays
     */
    public static function compare(
        TableDefinition $definition,
        array $existingColumns,
        array $existingIndexes,
        array $existingNullability = [],
    ): array {
        if (empty($existingColumns)) {
            return [['op' => 'create_table', 'definition' => $definition]];
        }

        $ops = [];

        foreach ($definition->columns as $col) {
            if (!array_key_exists($col->id, $existingColumns)) {
                $ops[] = ['op' => 'add_column', 'column' => $col];
            } else {
                $existingType = strtolower($existingColumns[$col->id]);
                // Uvolnění NOT NULL → NULL je bezpečné; opačný směr nikdy
                // (existující NULL hodnoty by MODIFY rozbily).
                $relaxesNullability = $col->nullable
                    && array_key_exists($col->id, $existingNullability)
                    && !$existingNullability[$col->id];
                if (self::isSafeModify($col, $existingType) || $relaxesNullability) {
                    $ops[] = ['op' => 'modify_column', 'column' => $col];
                }
            }
        }

        foreach ($definition->indexes as $index) {
            if (!in_array($index->id, $existingIndexes, true)) {
                $ops[] = ['op' => 'create_index', 'index' => $index];
            }
        }

        return $ops;
    }

    private static function isSafeModify(ColumnDefinition $def, string $existingType): bool
    {
        if ($def->type === 'varchar' && preg_match('/^varchar\((\d+)\)$/', $existingType, $m)) {
            return $def->length > (int) $m[1];
        }

        if ($def->type === 'smallint' && $existingType === 'tinyint') {
            return true;
        }

        if ($def->type === 'int' && in_array($existingType, ['smallint', 'tinyint'], true)) {
            return true;
        }

        if ($def->type === 'bigint' && in_array($existingType, ['int', 'smallint', 'tinyint'], true)) {
            return true;
        }

        if ($def->type === 'numeric' && preg_match('/^numeric\((\d+),\s*(\d+)\)$/', $existingType, $m)) {
            return $def->precision > (int) $m[1] && $def->scale === (int) $m[2];
        }

        return false;
    }
}
