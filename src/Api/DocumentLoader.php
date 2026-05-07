<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModuleResolver;

class DocumentLoader
{
    public static function load(DataSourceConfig $config, string $modulesBasePath): DocumentRegistry
    {
        $allModules      = ModuleLoader::loadAllModules($modulesBasePath);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        return new DocumentRegistry(self::mergeDocumentClasses($resolvedModules));
    }

    /**
     * Merge `documentClasses` registrations from multiple modules per table.
     *
     * Multi-module registrations for the same table are common with polymorphic
     * tables: `docs.core` registers `docs_core_heads` with typeColumn + defaultClass,
     * `docs.invoicesOut` adds `invno → IssuedInvoiceDocument`, `docs.invoicesIn`
     * adds `invni → ReceivedInvoiceDocument`. We merge their `classes` maps so
     * the registry has a single entry per table.
     *
     * @param array<int, ModuleDefinition> $modules
     * @return list<array<string, mixed>>
     */
    public static function mergeDocumentClasses(array $modules): array
    {
        /** @var array<string, array<string, mixed>> tableId → merged registration */
        $byTable = [];

        foreach ($modules as $module) {
            foreach ($module->documentClasses as $reg) {
                $table = $reg['table'] ?? null;
                if (!is_string($table) || $table === '') {
                    continue;
                }

                if (!isset($byTable[$table])) {
                    $byTable[$table] = $reg;
                    continue;
                }

                $existing = $byTable[$table];

                if (isset($reg['typeColumn'], $existing['typeColumn'])
                    && $reg['typeColumn'] !== $existing['typeColumn']
                ) {
                    throw new \LogicException(
                        "Conflicting typeColumn for table '{$table}': "
                        . "'{$existing['typeColumn']}' vs '{$reg['typeColumn']}' (module {$module->id})",
                    );
                }

                $merged = $existing;

                if (isset($reg['typeColumn']) && !isset($merged['typeColumn'])) {
                    $merged['typeColumn'] = $reg['typeColumn'];
                }

                if (isset($reg['classes']) && is_array($reg['classes'])) {
                    $merged['classes'] = $merged['classes'] ?? [];
                    foreach ($reg['classes'] as $typeKey => $className) {
                        if (isset($merged['classes'][$typeKey])
                            && $merged['classes'][$typeKey] !== $className
                        ) {
                            throw new \LogicException(
                                "Duplicate class registration for table '{$table}', "
                                . "type '{$typeKey}': '{$merged['classes'][$typeKey]}' vs '{$className}' "
                                . "(module {$module->id})",
                            );
                        }
                        $merged['classes'][$typeKey] = $className;
                    }
                }

                if (isset($reg['defaultClass']) && !isset($merged['defaultClass'])) {
                    $merged['defaultClass'] = $reg['defaultClass'];
                }

                if (isset($reg['class']) && !isset($merged['class']) && !isset($merged['typeColumn'])) {
                    $merged['class'] = $reg['class'];
                }

                $byTable[$table] = $merged;
            }
        }

        return array_values($byTable);
    }
}
