<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class SchemaIntrospector
{
    public const ENCRYPTED_COLUMN_TYPE = 'encrypted_text';

    /**
     * Find all encrypted_text columns across the given table definitions.
     * Caller is responsible for resolving extensions before calling.
     *
     * @param iterable<string, TableDefinition> $tables Keyed by table name.
     * @return list<array{table: string, column: string}>
     */
    public static function findEncryptedColumns(iterable $tables): array
    {
        $result = [];
        foreach ($tables as $tableName => $def) {
            foreach ($def->columns as $col) {
                if ($col->type === self::ENCRYPTED_COLUMN_TYPE) {
                    $result[] = ['table' => $tableName, 'column' => $col->id];
                }
            }
        }
        return $result;
    }
}
