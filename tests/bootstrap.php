<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;

ModuleClassLoader::register(
    new ModulePathResolver([dirname(__DIR__) . '/modules']),
);
