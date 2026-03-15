<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class TableDefinition
{
    public function __construct(
        public readonly int $tableId,
        public readonly string $name,
        public readonly array $columnGroups,
        public readonly array $columns,
        public readonly array $indexes,
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['tableId']) || !is_int($data['tableId']) || $data['tableId'] <= 0) {
            throw new \InvalidArgumentException('Table definition missing required field: tableId (must be positive int)');
        }

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            throw new \InvalidArgumentException('Table definition missing required field: name');
        }

        if (!isset($data['columns']) || !is_array($data['columns']) || count($data['columns']) === 0) {
            throw new \InvalidArgumentException('Table definition missing required field: columns (must be non-empty array)');
        }

        $columns = array_map(
            fn(array $col) => ColumnDefinition::fromArray($col),
            $data['columns'],
        );

        $primaryKeys = array_filter($columns, fn(ColumnDefinition $c) => $c->primaryKey);
        if (count($primaryKeys) === 0) {
            throw new \InvalidArgumentException("Table '{$data['name']}': must have exactly one column with primaryKey=true (found none)");
        }
        if (count($primaryKeys) > 1) {
            throw new \InvalidArgumentException("Table '{$data['name']}': must have exactly one column with primaryKey=true (found " . count($primaryKeys) . ')');
        }

        $indexes = array_map(
            fn(array $idx) => IndexDefinition::fromArray($idx),
            $data['indexes'] ?? [],
        );

        return new self(
            tableId: $data['tableId'],
            name: $data['name'],
            columnGroups: $data['columnGroups'] ?? [],
            columns: $columns,
            indexes: $indexes,
        );
    }
}
