<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Buildí `AlertCheckRegistry` pro konkrétní DS — analogicky `TableLoader`,
 * `ViewerLoader`, `FormLoader`, `DocumentLoader`.
 *
 * Načte všechny moduly, profiltruje je přes `ModuleResolver` podle toho, co
 * má DS povolené v `config/main.json` → `modules` (typicky `install.base`
 * a jeho tranzitivní dependencies), a postaví registr s lokalizovanými názvy.
 */
class AlertCheckLoader
{
    public static function load(DataSourceConfig $config, ModulePathResolver $resolver, string $language = 'en'): AlertCheckRegistry
    {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        return new AlertCheckRegistry($resolvedModules, $language);
    }
}
