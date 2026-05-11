<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\JsoncParser;

class SchemaLoader
{
    /**
     * Load module definitions, resolve dependencies, load all table
     * definitions, and apply extensions.
     *
     * @param array<int, string> $directModuleIds Module IDs from DataSourceConfig::getModules().
     * @return array{
     *     tables: array<string, TableDefinition>,
     *     errors: list<string>
     * }
     */
    public static function loadResolvedTables(ModulePathResolver $resolver, array $directModuleIds): array
    {
        $allModules = ModuleLoader::loadAllModules($resolver);
        $errors = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $directModuleIds, $errors);

        $tables = [];

        foreach ($resolvedModules as $module) {
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) continue;
            foreach ($module->tables as $tableFile) {
                $filePath = $modulePath . '/tables/' . $tableFile . '.jsonc';
                $raw = JsoncParser::parseFile($filePath);
                $tables[$tableFile] = TableDefinition::fromArray($raw);
            }
        }

        foreach ($resolvedModules as $module) {
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) continue;
            foreach ($module->extensions as $extFile) {
                $filePath = $modulePath . '/extensions/' . $extFile . '.jsonc';
                $extData = JsoncParser::parseFile($filePath);
                $ext = ExtensionDefinition::fromArray($extData);
                if (isset($tables[$ext->table])) {
                    $tables[$ext->table] = TableMerger::merge($tables[$ext->table], $ext);
                }
            }
        }

        return [
            'tables' => $tables,
            'errors' => $errors,
        ];
    }
}
