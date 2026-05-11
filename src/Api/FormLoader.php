<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

class FormLoader
{
    public static function load(DataSourceConfig $config, ModulePathResolver $resolver): FormRegistry
    {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $registry = new FormRegistry();
        $registry->loadFromModules($resolvedModules);

        return $registry;
    }
}
