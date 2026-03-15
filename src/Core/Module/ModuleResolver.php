<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

class ModuleResolver
{
    /**
     * @param ModuleDefinition[] $moduleDefinitions  All available modules
     * @param string[]           $activeModuleIds    Directly activated module IDs
     * @param string[]           $errors             Appended with warnings/errors (by reference)
     * @return ModuleDefinition[]                    Topologically sorted (deps before dependents)
     */
    public static function resolve(
        array $moduleDefinitions,
        array $activeModuleIds,
        array &$errors = [],
    ): array {
        $byId = [];
        foreach ($moduleDefinitions as $def) {
            $byId[$def->id] = $def;
        }

        $visited = [];
        $visiting = [];
        $result = [];

        foreach ($activeModuleIds as $id) {
            self::dfsVisit($id, $visited, $visiting, $result, $byId, $errors);
        }

        return $result;
    }

    private static function dfsVisit(
        string $id,
        array &$visited,
        array &$visiting,
        array &$result,
        array $byId,
        array &$errors,
    ): void {
        if (isset($visiting[$id])) {
            $errors[] = "Error: circular dependency detected involving module '$id', skipping";
            return;
        }

        if (isset($visited[$id])) {
            return;
        }

        if (!isset($byId[$id])) {
            $errors[] = "Warning: module '$id' not found, skipping";
            return;
        }

        $visiting[$id] = true;

        foreach ($byId[$id]->dependencies as $depId) {
            self::dfsVisit($depId, $visited, $visiting, $result, $byId, $errors);
        }

        unset($visiting[$id]);
        $visited[$id] = true;
        $result[] = $byId[$id];
    }
}
