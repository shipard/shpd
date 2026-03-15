<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class IndexDefinition
{
    private const ALLOWED_TYPES = ['index', 'unique', 'fulltext'];

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $columns,
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !is_string($data['id']) || $data['id'] === '') {
            throw new \InvalidArgumentException('Index definition missing required field: id');
        }

        if (!isset($data['type']) || !is_string($data['type'])) {
            throw new \InvalidArgumentException('Index definition missing required field: type');
        }

        if (!in_array($data['type'], self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Index definition has invalid type: '{$data['type']}'");
        }

        if (!isset($data['columns']) || !is_array($data['columns']) || count($data['columns']) === 0) {
            throw new \InvalidArgumentException("Index '{$data['id']}': 'columns' must be a non-empty array");
        }

        $columns = [];
        foreach ($data['columns'] as $entry) {
            if (!isset($entry['column']) || !is_string($entry['column']) || $entry['column'] === '') {
                throw new \InvalidArgumentException("Index '{$data['id']}': each column entry must have a 'column' field");
            }
            $order = $entry['order'] ?? 'ASC';
            if (!in_array($order, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException("Index '{$data['id']}': invalid order '{$order}', must be 'ASC' or 'DESC'");
            }
            $columns[] = ['column' => $entry['column'], 'order' => $order];
        }

        return new self(
            id: $data['id'],
            type: $data['type'],
            columns: $columns,
        );
    }
}
