<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Sběr `journalEventHandlers` registrací z resolvovaných modulů →
 * JournalEventDispatcher. Mirror DocumentEventHandlerLoader; čte se za běhu
 * z module.jsonc, žádná kompilace do cfg.
 */
class JournalEventHandlerLoader
{
    public static function load(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        ?\Dibi\Connection $db = null,
        ?ConfigRuntime $configRuntime = null,
    ): JournalEventDispatcher {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $registrations = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->journalEventHandlers as $reg) {
                $registrations[] = $reg;
            }
        }

        return new JournalEventDispatcher($registrations, $db, $configRuntime, $config);
    }
}
