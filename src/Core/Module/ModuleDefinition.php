<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

class ModuleDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly array $dependencies,
        public readonly array $tables,
        public readonly array $extensions,
        public readonly array $config,
        public readonly array $documentClasses,
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !is_string($data['id']) || $data['id'] === '') {
            throw new \InvalidArgumentException('Module definition missing required field: id');
        }

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            throw new \InvalidArgumentException('Module definition missing required field: name');
        }

        if (!preg_match('/^[a-z][a-z0-9]*\.[a-z][a-z0-9]*$/', $data['id'])) {
            throw new \InvalidArgumentException("Invalid module id format: '{$data['id']}'");
        }

        return new self(
            id: $data['id'],
            name: $data['name'],
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : '',
            dependencies: $data['dependencies'] ?? [],
            tables: $data['tables'] ?? [],
            extensions: $data['extensions'] ?? [],
            config: $data['config'] ?? [],
            documentClasses: $data['documentClasses'] ?? [],
        );
    }
}
