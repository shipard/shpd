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

        $registrations = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->documentClasses as $reg) {
                $registrations[] = $reg;
            }
        }

        return new DocumentRegistry($registrations);
    }
}
