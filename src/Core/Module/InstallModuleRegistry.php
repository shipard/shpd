<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

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
     * @return list<array{id: string, name: string, description: string}>
     *         Sorted by name (case-insensitive).
     */
    public function list(): array
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
                $def = ModuleLoader::loadModule($path);
            } catch (\Throwable) {
                // Skip malformed modules — registry is best-effort.
                continue;
            }

            $modules[] = [
                'id'          => $def->id,
                'name'        => $def->name,
                'description' => $def->description,
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
