<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Sběr `attachmentGuards` registrací z resolvovaných modulů (#55 X16) —
 * stejný vzor jako DocumentEventHandlerLoader: žádná kompilace do cfg,
 * čte se za běhu z module.jsonc.
 *
 * Výsledek je mapa `tabulka → seznam tříd`; instancuje je až
 * `AttachmentService`, a jen když se s přílohou opravdu hýbe.
 */
class AttachmentGuardLoader
{
    /** @return array<string, list<class-string>> */
    public static function load(DataSourceConfig $config, ModulePathResolver $resolver): array
    {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $guards = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->attachmentGuards as $registration) {
                $guards[$registration['table']][] = $registration['class'];
            }
        }
        return $guards;
    }
}
