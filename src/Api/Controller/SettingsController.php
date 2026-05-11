<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\JsoncParser;

class SettingsController
{
    public function navigation(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language,
        ?ConfigRuntime $configRuntime,
    ): Response {
        if ($configRuntime === null) {
            return Response::success([]);
        }

        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $sectionsCfg = $configRuntime->cfgItem('global.settingsSections');
        if (!is_array($sectionsCfg) || empty($sectionsCfg['sections'])) {
            return Response::success([]);
        }

        $sections = $sectionsCfg['sections'];
        usort($sections, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $itemsBySection = $this->collectItems($resolvedModules, $resolver, $language);

        $tree = [];
        foreach ($sections as $section) {
            $sectionId = $section['id'];
            if (empty($itemsBySection[$sectionId])) {
                continue;
            }

            $label = $section['name:' . $language]
                ?? $section['name:en']
                ?? $section['name']
                ?? $sectionId;

            $tree[] = [
                'id'       => $sectionId,
                'label'    => $label,
                'icon'     => $section['icon'] ?? null,
                'children' => $itemsBySection[$sectionId],
            ];
        }

        return Response::success($tree);
    }

    /**
     * @param  ModuleDefinition[]  $resolvedModules
     * @return array<string, array>
     */
    private function collectItems(array $resolvedModules, ModulePathResolver $resolver, string $language): array
    {
        $itemsBySection = [];
        $seenViewers = [];
        $seenTables = [];

        foreach ($resolvedModules as $module) {
            foreach ($module->settingsItems as $item) {
                $section = $item['section'];

                if ($item['viewer'] !== null) {
                    $viewerId = $item['viewer'];
                    if (isset($seenViewers[$viewerId])) {
                        continue;
                    }

                    $viewerDef = null;
                    foreach ($module->viewers as $v) {
                        if (($v['id'] ?? '') === $viewerId) {
                            $viewerDef = $v;
                            break;
                        }
                    }

                    if ($viewerDef === null) {
                        ErrorLogger::warn('Viewer not found in module, skipping', [
                            'viewer_id' => $viewerId,
                            'module_id' => $module->id,
                        ]);
                        continue;
                    }

                    // Respect hideFromNavigation on the viewer's target table —
                    // a designer marking a table as hidden should not have to
                    // separately remove it from settingsItems[]. Mismatch is a
                    // configuration error worth logging.
                    $targetTable = $viewerDef['table'] ?? null;
                    if ($targetTable !== null) {
                        $modulePath = $resolver->getPath($module->id);
                        if ($modulePath !== null && $this->isTableHiddenFromNavigation($modulePath, $targetTable)) {
                            ErrorLogger::warn('Viewer targets hidden table, skipping', [
                                'viewer_id'  => $viewerId,
                                'table_name' => $targetTable,
                                'module_id'  => $module->id,
                            ]);
                            continue;
                        }
                    }

                    $seenViewers[$viewerId] = true;
                    $label = $this->localizeViewerName($viewerDef, $language);

                    $navItem = [
                        'id'       => 'viewer:' . $viewerId,
                        'label'    => $label,
                        'type'     => 'viewer',
                        'viewerId' => $viewerId,
                    ];
                    if (isset($viewerDef['icon'])) {
                        $navItem['icon'] = $viewerDef['icon'];
                    }

                    if ($item['order'] !== null) {
                        $navItem['_order'] = $item['order'];
                    }

                    $itemsBySection[$section][] = $navItem;
                } elseif ($item['table'] !== null) {
                    $tableName = $item['table'];
                    if (isset($seenTables[$tableName])) {
                        continue;
                    }
                    if (!in_array($tableName, $module->tables, true)) {
                        ErrorLogger::warn('Table not found in module, skipping', [
                            'table_name' => $tableName,
                            'module_id'  => $module->id,
                        ]);
                        continue;
                    }

                    $modulePath = $resolver->getPath($module->id);
                    if ($modulePath === null) {
                        continue;
                    }

                    if ($this->isTableHiddenFromNavigation($modulePath, $tableName)) {
                        ErrorLogger::warn('Table is marked hideFromNavigation, skipping', [
                            'table_name' => $tableName,
                            'module_id'  => $module->id,
                        ]);
                        continue;
                    }

                    $seenTables[$tableName] = true;
                    $tableData  = $this->loadTableMeta($modulePath, $tableName, $language);

                    $navItem = [
                        'id'    => $tableName,
                        'label' => $tableData['name'] ?? $tableName,
                        'type'  => 'table',
                        'table' => $tableName,
                    ];
                    if (isset($tableData['icon'])) {
                        $navItem['icon'] = $tableData['icon'];
                    }
                    if ($item['order'] !== null) {
                        $navItem['_order'] = $item['order'];
                    }

                    $itemsBySection[$section][] = $navItem;
                }
            }
        }

        foreach ($itemsBySection as $section => $items) {
            $hasOrder = array_any($items, fn($i) => isset($i['_order']));
            if ($hasOrder) {
                usort($itemsBySection[$section], fn($a, $b) => ($a['_order'] ?? PHP_INT_MAX) <=> ($b['_order'] ?? PHP_INT_MAX));
            }
            $itemsBySection[$section] = array_map(static function (array $i): array {
                unset($i['_order']);
                return $i;
            }, $itemsBySection[$section]);
        }

        return $itemsBySection;
    }

    private function localizeViewerName(array $viewer, string $language): string
    {
        return $viewer['name:' . $language] ?? $viewer['name:en'] ?? $viewer['name'] ?? $viewer['id'];
    }

    private function loadTableMeta(string $modulePath, string $tableName, string $language): array
    {
        $filePath = $modulePath . '/tables/' . $tableName . '.jsonc';
        if (!file_exists($filePath)) {
            return ['name' => $tableName];
        }
        $raw       = JsoncParser::parseFile($filePath);
        $localized = ConfigLocalizer::localize($raw, $language);
        return $localized;
    }

    private function isTableHiddenFromNavigation(string $modulePath, string $tableName): bool
    {
        $filePath = $modulePath . '/tables/' . $tableName . '.jsonc';
        if (!file_exists($filePath)) {
            return false;
        }
        $raw = JsoncParser::parseFile($filePath);
        return !empty($raw['hideFromNavigation']);
    }
}
