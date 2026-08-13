<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;

/**
 * Discovers install modules (top-level bundles in `modules/install/<name>/`).
 * An install module is a regular module with id matching `install.*`.
 */
final class InstallModuleRegistry
{
    public function __construct(
        private readonly ModulePathResolver $modulePathResolver,
    ) {}

    /**
     * @param string|null $language localize name/description (fallback chain
     *                              lang → en → bare); null = bare fields
     * @param bool $selfServiceOnly only modules with top-level
     *                              `"selfService": true` (hosting-08 D2 —
     *                              offered in portal self-service DS creation)
     * @return list<array{id: string, name: string, description: string}>
     *         Sorted by name (case-insensitive).
     */
    public function list(?string $language = null, bool $selfServiceOnly = false): array
    {
        $modules = [];

        foreach ($this->modulePathResolver->allModuleIds() as $id) {
            if (!str_starts_with($id, 'install.')) {
                continue;
            }
            $path = $this->modulePathResolver->getPath($id);
            if ($path === null) {
                continue;
            }

            try {
                // Raw jsonc navíc k ModuleDefinition: `selfService` a `name:cs`
                // varianty fromArray() zahazuje (whitelist reader).
                $raw = JsoncParser::parseFile($path . '/module.jsonc');
                $def = ModuleDefinition::fromArray($raw);
            } catch (\Throwable) {
                // Skip malformed modules — registry is best-effort.
                continue;
            }

            if ($selfServiceOnly && ($raw['selfService'] ?? false) !== true) {
                continue;
            }

            $localized = $language !== null ? ConfigLocalizer::localize($raw, $language) : [];

            $modules[] = [
                'id'          => $def->id,
                'name'        => is_string($localized['name'] ?? null) ? $localized['name'] : $def->name,
                'description' => is_string($localized['description'] ?? null) ? $localized['description'] : $def->description,
            ];
        }

        usort(
            $modules,
            fn(array $a, array $b): int => strcmp(
                mb_strtolower($a['name']),
                mb_strtolower($b['name']),
            ),
        );

        return $modules;
    }

    /**
     * Checks whether a given module id exists in `modules/install/`.
     */
    public function exists(string $moduleId): bool
    {
        if (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $moduleId)) {
            return false;
        }
        return $this->modulePathResolver->getPath($moduleId) !== null;
    }
}
