<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

class ConfigCompiler
{
    /**
     * Verze formátu kompilovaného configu — nezávislá na verzi aplikace
     * (Shipard\Core\Version). Bumpovat při změně struktury kompilátu.
     */
    private const VERSION = '0.1.0';

    /** @param ModuleDefinition[] $modules Resolved modules in dependency order */
    public static function compile(
        array $modules,
        ModulePathResolver $resolver,
        array $languages,
        string $outputPath,
    ): void {
        $rawItems = [];
        $moduleIds = [];

        foreach ($modules as $module) {
            $moduleIds[] = $module->id;
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) continue;

            foreach ($module->config as $entry) {
                $cfgId = $entry['id'];
                $filePath = $modulePath . '/' . $entry['file'];
                $rawItems[$cfgId] = JsoncParser::parseFile($filePath);
            }
        }

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        foreach ($languages as $language) {
            $localizedItems = [];
            foreach ($rawItems as $cfgId => $rawData) {
                $localizedItems[$cfgId] = ConfigLocalizer::localize($rawData, $language);
            }

            $output = [
                '_meta' => [
                    'compiled' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'version' => self::VERSION,
                    'language' => $language,
                    'modules' => $moduleIds,
                ],
                'items' => $localizedItems,
            ];

            file_put_contents(
                $outputPath . '/compiled.' . $language . '.json',
                json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
        }
    }
}
