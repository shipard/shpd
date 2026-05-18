<?php

declare(strict_types=1);

namespace Shipard\Core\Alerts;

use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleDefinition;

/**
 * Agreguje `alertChecks` ze všech zaregistrovaných modulů do jednoho registru
 * indexovaného přes globální `check_id`.
 *
 * - Lokalizace: každý surový záznam z `ModuleDefinition->alertChecks` projde
 *   `ConfigLocalizer::localize($lang)` (stejný mechanismus jako tabulky
 *   v `TableLoader`), takže `name:cs`/`description:cs` se vyřeší na zvolený
 *   jazyk DS dřív, než se předá `AlertCheckDefinition::fromArray()`.
 * - Duplicita: pokud dva moduly registrují check se stejným `id`, registry
 *   v konstruktoru hodí `\RuntimeException`.
 */
final class AlertCheckRegistry
{
    /** @var array<string, AlertCheckDefinition> Indexed by check id. */
    private readonly array $checks;

    /**
     * @param ModuleDefinition[] $modules
     * @throws \RuntimeException Při duplicitě check id napříč moduly.
     * @throws \InvalidArgumentException Při invalidním alertChecks záznamu.
     */
    public function __construct(array $modules, string $language)
    {
        $byId = [];
        foreach ($modules as $module) {
            foreach ($module->alertChecks as $raw) {
                $localized = ConfigLocalizer::localize($raw, $language);
                $def       = AlertCheckDefinition::fromArray($localized, $module->id);

                if (isset($byId[$def->id])) {
                    $existing = $byId[$def->id];
                    throw new \RuntimeException(
                        "AlertCheckRegistry: duplicate check id '{$def->id}'"
                        . " — registered in '{$existing->moduleId}' and '{$module->id}'",
                    );
                }
                $byId[$def->id] = $def;
            }
        }
        $this->checks = $byId;
    }

    /** @return AlertCheckDefinition[] Všechny zaregistrované checky, vč. disabled. */
    public function getAll(): array
    {
        return array_values($this->checks);
    }

    /** @return AlertCheckDefinition[] Jen ty, které mají `enabled: true` v JSONC. */
    public function getEnabled(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (AlertCheckDefinition $d): bool => $d->enabled,
        ));
    }

    public function get(string $checkId): ?AlertCheckDefinition
    {
        return $this->checks[$checkId] ?? null;
    }
}
