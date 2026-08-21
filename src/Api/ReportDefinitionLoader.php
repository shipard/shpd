<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Reports\ReportDefinition;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Utils\JsoncParser;

/**
 * Buildí `ReportRegistry` pro konkrétní DS — analogicky `LookupLoader`,
 * `AlertCheckLoader`. Projde resolvnuté moduly, načte JSONC soubory z klíče
 * `reports` (cesty relativně k adresáři modulu, vzor cfgItems
 * v `ConfigCompiler`), aplikuje i18n (`name:cs`) a naplní registr.
 * Duplicitní id reportu napříč moduly = tvrdá chyba při načtení.
 */
class ReportDefinitionLoader
{
    public static function load(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language = 'en',
    ): ReportRegistry {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $registry = new ReportRegistry();
        foreach ($resolvedModules as $module) {
            if ($module->reports === []) {
                continue;
            }
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) {
                continue;
            }

            foreach ($module->reports as $entry) {
                $filePath     = $modulePath . '/' . $entry['file'];
                $declarations = JsoncParser::parseFile($filePath);
                if (!is_array($declarations)) {
                    throw new \RuntimeException(
                        "Module '{$module->id}': report file '{$entry['file']}' must contain an array of declarations",
                    );
                }
                foreach ($declarations as $raw) {
                    if (!is_array($raw)) {
                        throw new \RuntimeException(
                            "Module '{$module->id}': report file '{$entry['file']}' contains a non-object declaration",
                        );
                    }
                    $localized = ConfigLocalizer::localize($raw, $language);
                    $registry->add(ReportDefinition::fromArray($localized, $module->id));
                }
            }
        }

        return $registry;
    }
}
