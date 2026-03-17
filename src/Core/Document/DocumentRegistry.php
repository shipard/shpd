<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class DocumentRegistry
{
    /** @var array<string, array> tableId → registration */
    private array $registrations = [];

    public function __construct(array $registrations = [])
    {
        foreach ($registrations as $registration) {
            $this->registrations[$registration['table']] = $registration;
        }
    }

    public function getDocument(string $tableId, array $data = []): Document
    {
        if (!isset($this->registrations[$tableId])) {
            return new DefaultDocument();
        }

        $reg = $this->registrations[$tableId];

        if (isset($reg['typeColumn'])) {
            $typeValue = $data[$reg['typeColumn']] ?? '';
            $className = $reg['classes'][$typeValue] ?? $reg['defaultClass'] ?? null;
            return $className ? new $className() : new DefaultDocument();
        }

        if (isset($reg['class'])) {
            $className = $reg['class'];
            return new $className();
        }

        return new DefaultDocument();
    }
}
