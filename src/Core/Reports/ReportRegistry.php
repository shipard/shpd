<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Registr deklarovaných reportů per DS — plní ho `ReportDefinitionLoader`
 * z klíče `reports` v module.jsonc. Duplicitní id napříč moduly = tvrdá
 * chyba při načtení (failing loudly).
 */
final class ReportRegistry
{
    /** @var array<string, ReportDefinition> */
    private array $reports = [];

    public function add(ReportDefinition $definition): void
    {
        if (isset($this->reports[$definition->id])) {
            $existing = $this->reports[$definition->id];
            throw new \RuntimeException(
                "ReportRegistry: duplicate report id '{$definition->id}'"
                . " — registered in '{$existing->moduleId}' and '{$definition->moduleId}'",
            );
        }
        $this->reports[$definition->id] = $definition;
    }

    public function get(string $reportId): ?ReportDefinition
    {
        return $this->reports[$reportId] ?? null;
    }

    /** @return ReportDefinition[] */
    public function getAll(): array
    {
        return array_values($this->reports);
    }
}
