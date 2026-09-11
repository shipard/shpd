<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

class DocumentRegistry
{
    /** @var array<string, array> tableId → registration */
    private array $registrations = [];

    /**
     * Registrace providerů zámku (`documentLockProviders`, #55 D24) — jedou
     * na registru dokumentů, aby je měla po ruce každá TableGateway. Instance
     * s DB a konfigurací staví až DocumentLockRegistry::forDocuments().
     *
     * @var list<array{table: string, class: string}>
     */
    private array $lockProviders = [];

    /** @var array<string, true> tabulky s aspoň jedním providerem */
    private array $lockTables = [];

    /**
     * @param list<array{table: string, class: string}> $lockProviders
     */
    public function __construct(array $registrations = [], array $lockProviders = [])
    {
        foreach ($registrations as $registration) {
            $this->registrations[$registration['table']] = $registration;
        }
        foreach ($lockProviders as $reg) {
            $this->lockProviders[] = ['table' => $reg['table'], 'class' => $reg['class']];
            $this->lockTables[$reg['table']] = true;
        }
    }

    /** @return list<array{table: string, class: string}> */
    public function getLockProviders(): array
    {
        return $this->lockProviders;
    }

    public function hasLockProviders(string $tableId): bool
    {
        return isset($this->lockTables[$tableId]);
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
