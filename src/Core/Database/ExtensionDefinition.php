<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class ExtensionDefinition
{
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $columnGroups,
        public readonly array $indexes,
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['table']) || !is_string($data['table']) || $data['table'] === '') {
            throw new \InvalidArgumentException('Extension definition missing required field: table');
        }

        $columns = array_map(
            fn(array $col) => ColumnDefinition::fromArray($col),
            $data['columns'] ?? [],
        );

        $columnGroups = [];
        foreach ($data['columnGroups'] ?? [] as $group) {
            $columnGroups[] = $group;
        }

        $indexes = array_map(
            fn(array $idx) => IndexDefinition::fromArray($idx),
            $data['indexes'] ?? [],
        );

        return new self(
            table: $data['table'],
            columns: $columns,
            columnGroups: $columnGroups,
            indexes: $indexes,
        );
    }
}
