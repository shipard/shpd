<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Sběr `documentEventHandlers` registrací z resolvovaných modulů →
 * DocumentEventDispatcher. Stejný vzor jako DocumentLoader / LookupLoader:
 * žádná kompilace do cfg, čte se za běhu z module.jsonc.
 */
class DocumentEventHandlerLoader
{
    public static function load(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        ?\Dibi\Connection $db = null,
        ?ConfigRuntime $configRuntime = null,
    ): DocumentEventDispatcher {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $registrations = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->documentEventHandlers as $reg) {
                $registrations[] = $reg;
            }
        }

        return new DocumentEventDispatcher($registrations, $db, $configRuntime, $config);
    }
}
