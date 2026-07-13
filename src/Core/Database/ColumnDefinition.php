<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class ColumnDefinition
{
    private const ALLOWED_TYPES = [
        'tinyint', 'smallint', 'int', 'bigint',
        'varchar', 'text', 'longtext',
        'numeric', 'float',
        'date', 'datetime', 'time',
        'boolean', 'json',
        'enumInt', 'enumString',
        'encrypted_text',
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $formLabel,
        public readonly string $type,
        public readonly ?int $length,
        public readonly ?int $precision,
        public readonly ?int $scale,
        public readonly bool $nullable,
        public readonly mixed $default,
        public readonly bool $autoIncrement,
        public readonly bool $primaryKey,
        public readonly ?string $group,
        public readonly ?string $collation,
        public readonly ?string $cfgItem,
        public readonly ?string $reference,
        public readonly bool $system,
        public readonly bool $sensitive = false,
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !is_string($data['id']) || $data['id'] === '') {
            throw new \InvalidArgumentException('Column definition missing required field: id');
        }

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            throw new \InvalidArgumentException('Column definition missing required field: name');
        }

        if (!isset($data['type']) || !is_string($data['type'])) {
            throw new \InvalidArgumentException('Column definition missing required field: type');
        }

        if (!in_array($data['type'], self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Column definition has invalid type: '{$data['type']}'");
        }

        $type = $data['type'];

        if ($type === 'varchar' && (!isset($data['length']) || !is_int($data['length']))) {
            throw new \InvalidArgumentException("Column '{$data['id']}': type 'varchar' requires 'length'");
        }

        if ($type === 'numeric') {
            if (!isset($data['precision']) || !is_int($data['precision'])) {
                throw new \InvalidArgumentException("Column '{$data['id']}': type 'numeric' requires 'precision'");
            }
            if (!isset($data['scale']) || !is_int($data['scale'])) {
                throw new \InvalidArgumentException("Column '{$data['id']}': type 'numeric' requires 'scale'");
            }
        }

        if ($type === 'enumInt' && (!isset($data['cfgItem']) || !is_string($data['cfgItem']) || $data['cfgItem'] === '')) {
            throw new \InvalidArgumentException("Column '{$data['id']}': type 'enumInt' requires 'cfgItem'");
        }

        if ($type === 'enumString') {
            if (!isset($data['cfgItem']) || !is_string($data['cfgItem']) || $data['cfgItem'] === '') {
                throw new \InvalidArgumentException("Column '{$data['id']}': type 'enumString' requires 'cfgItem'");
            }
            if (!isset($data['length']) || !is_int($data['length'])) {
                throw new \InvalidArgumentException("Column '{$data['id']}': type 'enumString' requires 'length'");
            }
        }

        return new self(
            id: $data['id'],
            name: $data['name'],
            formLabel: isset($data['formLabel']) && is_string($data['formLabel']) && $data['formLabel'] !== ''
                ? $data['formLabel']
                : null,
            type: $type,
            length: $data['length'] ?? null,
            precision: $data['precision'] ?? null,
            scale: $data['scale'] ?? null,
            nullable: $data['nullable'] ?? false,
            default: $data['default'] ?? null,
            autoIncrement: $data['autoIncrement'] ?? false,
            primaryKey: $data['primaryKey'] ?? false,
            group: $data['group'] ?? null,
            collation: $data['collation'] ?? null,
            cfgItem: $data['cfgItem'] ?? null,
            reference: isset($data['reference']) && is_string($data['reference']) && $data['reference'] !== ''
                ? $data['reference']
                : null,
            system: (bool) ($data['system'] ?? false),
            sensitive: (bool) ($data['sensitive'] ?? false),
        );
    }
}
