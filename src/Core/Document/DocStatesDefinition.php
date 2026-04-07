<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

/**
 * Parsed `docStates` block from a table definition.
 * References the cfgItem that holds the full state machine configuration.
 */
class DocStatesDefinition
{
    public function __construct(
        public readonly string $stateColumn,
        public readonly string $mainColumn,
        public readonly string $cfgItem,
    ) {}

    public static function fromArray(array $data): self
    {
        if (empty($data['cfgItem']) || !is_string($data['cfgItem'])) {
            throw new \InvalidArgumentException('docStates requires a non-empty string cfgItem');
        }

        return new self(
            stateColumn: isset($data['stateColumn']) && is_string($data['stateColumn'])
                ? $data['stateColumn']
                : 'docState',
            mainColumn: isset($data['mainColumn']) && is_string($data['mainColumn'])
                ? $data['mainColumn']
                : 'docStateMain',
            cfgItem: $data['cfgItem'],
        );
    }
}
