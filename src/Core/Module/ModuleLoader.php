<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Utils\JsoncParser;

class ModuleLoader
{
    public static function loadModule(string $modulePath): ModuleDefinition
    {
        $file = $modulePath . '/module.jsonc';
        if (!file_exists($file)) {
            throw new \RuntimeException("Module definition not found: $file");
        }

        $data = JsoncParser::parseFile($file);
        return ModuleDefinition::fromArray($data);
    }

    /** @return ModuleDefinition[] */
    public static function loadAllModules(string $modulesBasePath): array
    {
        $dirs = glob("$modulesBasePath/*/*", GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }

        $modules = [];
        foreach ($dirs as $dir) {
            if (!file_exists($dir . '/module.jsonc')) {
                continue;
            }
            $modules[] = self::loadModule($dir);
        }

        return $modules;
    }
}
