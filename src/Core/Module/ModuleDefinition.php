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
        public readonly array $viewers,
        public readonly array $forms,
        public readonly array $settingsItems,
        public readonly array $lookups = [],
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !is_string($data['id']) || $data['id'] === '') {
            throw new \InvalidArgumentException('Module definition missing required field: id');
        }

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            throw new \InvalidArgumentException('Module definition missing required field: name');
        }

        // Module id format: <group>.<module>
        //   <group>  — lowercase alphanumeric (group directory name)
        //   <module> — alphanumeric, must start lowercase. camelCase is allowed
        //              for multi-word module names like `docs.invoicesOut`.
        if (!preg_match('/^[a-z][a-z0-9]*\.[a-z][a-zA-Z0-9]*$/', $data['id'])) {
            throw new \InvalidArgumentException("Invalid module id format: '{$data['id']}'");
        }

        $settingsItems = [];
        if (isset($data['settingsItems']) && is_array($data['settingsItems'])) {
            foreach ($data['settingsItems'] as $item) {
                if (!is_array($item)) continue;
                if (!isset($item['section'])) continue;
                if (!isset($item['viewer']) && !isset($item['table'])) continue;
                if (isset($item['viewer']) && isset($item['table'])) continue;
                $settingsItems[] = [
                    'viewer'  => $item['viewer'] ?? null,
                    'table'   => $item['table']  ?? null,
                    'section' => (string) $item['section'],
                    'order'   => isset($item['order']) ? (int) $item['order'] : null,
                ];
            }
        }

        $lookups = [];
        if (isset($data['lookups']) && is_array($data['lookups'])) {
            foreach ($data['lookups'] as $lookup) {
                if (!is_array($lookup)) continue;
                if (!isset($lookup['table']) || !is_string($lookup['table']) || $lookup['table'] === '') continue;
                if (!isset($lookup['class']) || !is_string($lookup['class']) || $lookup['class'] === '') continue;
                $lookups[] = [
                    'table' => $lookup['table'],
                    'class' => $lookup['class'],
                ];
            }
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
            viewers: $data['viewers'] ?? [],
            forms: $data['forms'] ?? [],
            settingsItems: $settingsItems,
            lookups: $lookups,
        );
    }
}
