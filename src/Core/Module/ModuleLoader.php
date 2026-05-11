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
    public static function loadAllModules(ModulePathResolver $resolver): array
    {
        $modules = [];
        foreach ($resolver->allModuleIds() as $id) {
            $path = $resolver->getPath($id);
            if ($path === null) continue;
            $modules[] = self::loadModule($path);
        }

        return $modules;
    }
}
