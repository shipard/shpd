<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;

/**
 * Hlavní navigace (sidebar) v app módu.
 *
 * Sekce sidebaru jsou sémantické — definuje je cfgItem `global.navSections`
 * (modules/install/base/config/navSections.jsonc), NE prefix module ID.
 * Každý viewer / tabulka nese `navSection` (id sekce) a volitelně `navOrder`
 * (int pořadí v sekci). `navigation()` seskupuje podle `navSection`, řadí
 * sekce dle navSections.order a položky dle navOrder.
 *
 * Sentinel `navSection: "_top"` = root-level leaf nad sekcemi (Došlá pošta,
 * Úkoly). Dashboard a Chat zůstávají hardcoded root leaves (nejsou viewery).
 * Co nemá `navSection` (nebo má neznámou sekci) → fallback do `system`.
 *
 * API tvar odpovědi (id/label/children/type/icon/viewerId/table) je shodný
 * s dřívějším prefix-groupingem — Sidebar.svelte se nemění.
 */
class NavigationController
{
    /** Sentinel navSection — root-level leaf nad sekcemi (Došlá pošta, Úkoly). */
    private const string TOP_SECTION = '_top';

    /** Fallback sekce pro itemy bez navSection / s neznámou navSection. */
    private const string FALLBACK_SECTION = 'system';

    /** navOrder pro položky bez explicitního pořadí — padají na konec sekce. */
    private const int NAV_ORDER_DEFAULT = 1000;

    /**
     * Vestavěný fallback sekcí pro případ, že compiled config (cfgItem
     * `global.navSections`) chybí — navigace funguje degradovaně, ne crash.
     * Drží stejná id/pořadí jako navSections.jsonc; labely cs/en přímo zde.
     */
    private const array SECTIONS_FALLBACK = [
        ['id' => 'basic',      'order' => 10, 'cs' => 'Základní', 'en' => 'Basic'],
        ['id' => 'purchase',   'order' => 20, 'cs' => 'Nákup',    'en' => 'Purchase'],
        ['id' => 'sales',      'order' => 30, 'cs' => 'Prodej',   'en' => 'Sales'],
        ['id' => 'accounting', 'order' => 40, 'cs' => 'Účtárna',  'en' => 'Accounting'],
        ['id' => 'system',     'order' => 50, 'cs' => 'Systém',   'en' => 'System'],
    ];

    private const array SKIP_PATTERNS = [
        'sessions',
        'rate_limits',
        'api_keys',
    ];

    public function navigation(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language,
        ?ConfigRuntime $configRuntime = null,
    ): Response {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $hiddenViewers = [];
        $hiddenTables  = [];

        // (1) Viewers/tables listed in any module's settingsItems are managed
        // via the Settings UI — hide them from the main navigation.
        foreach ($resolvedModules as $module) {
            foreach ($module->settingsItems as $item) {
                if ($item['viewer'] !== null) {
                    $hiddenViewers[$item['viewer']] = true;
                }
                if ($item['table'] !== null) {
                    $hiddenTables[$item['table']] = true;
                }
            }
        }

        // (2) Viewer-level `hideFromNavigation` (declared on the viewer in
        // module.jsonc) — hides that specific viewer. Used for the summary
        // docs.core.heads viewer over the shared docs_core_heads table.
        foreach ($resolvedModules as $module) {
            foreach ($module->viewers as $viewer) {
                if (!empty($viewer['hideFromNavigation']) && isset($viewer['id'])) {
                    $hiddenViewers[$viewer['id']] = true;
                }
            }
        }

        // (3) Table-level `hideFromNavigation` (declared in the table's .jsonc,
        // typically sub-tables of a parent record — fiscal_months, doc rows…).
        foreach ($resolvedModules as $module) {
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) {
                continue;
            }
            foreach ($module->tables as $tableName) {
                if ($this->isTableHiddenFromNavigation($modulePath, $tableName)) {
                    $hiddenTables[$tableName] = true;
                }
            }
        }

        // (4) Every table that ANY viewer targets — across ALL modules. A table
        // represented by a viewer is never rendered as a raw fallback table
        // item, even when all its viewers are hidden. Computed globally so a
        // per-type viewer (docs.invoicesIn.heads) covers a shared table
        // (docs_core_heads) owned by another module.
        $tablesWithViewer = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->viewers as $viewer) {
                if (isset($viewer['table'])) {
                    $tablesWithViewer[$viewer['table']] = true;
                }
            }
        }

        // (5) table → viewer propagation: a hidden table (hideFromNavigation or
        // settingsItems) hides any viewer targeting it. We deliberately do NOT
        // propagate viewer → table: hiding the summary docs.core.heads viewer
        // must not hide docs_core_heads and break the invoice viewers that
        // share it. Fallback-item suppression is handled by $tablesWithViewer.
        foreach ($resolvedModules as $module) {
            foreach ($module->viewers as $viewer) {
                $viewerId  = $viewer['id'] ?? null;
                $tableName = $viewer['table'] ?? null;
                if ($viewerId !== null && $tableName !== null && isset($hiddenTables[$tableName])) {
                    $hiddenViewers[$viewerId] = true;
                }
            }
        }

        $sections     = $this->loadSections($configRuntime, $language);
        $sectionIndex = [];
        foreach ($sections as $section) {
            $sectionIndex[$section['id']] = true;
        }

        $items = $this->collectItems(
            $resolvedModules,
            $resolver,
            $language,
            $hiddenViewers,
            $hiddenTables,
            $tablesWithViewer,
        );

        // Bucket by navSection. `_top` → root-level leaves; unknown → fallback.
        $topLeaves = [];
        $buckets   = [];
        foreach ($items as $item) {
            $sec = $item['_section'];
            if ($sec === self::TOP_SECTION) {
                $topLeaves[] = $item;
                continue;
            }
            if (!isset($sectionIndex[$sec])) {
                $sec = self::FALLBACK_SECTION;
            }
            $buckets[$sec][] = $item;
        }

        $this->sortItems($topLeaves);
        foreach ($buckets as &$bucket) {
            $this->sortItems($bucket);
        }
        unset($bucket);

        // Build the section tree in navSections.order; skip empty sections.
        $tree = [];
        foreach ($sections as $section) {
            $sectionId = $section['id'];
            if (empty($buckets[$sectionId])) {
                continue;
            }
            $tree[] = [
                'id'       => $sectionId,
                'label'    => $section['label'],
                'icon'     => $section['icon'] ?? null,
                'children' => array_map([$this, 'cleanItem'], $buckets[$sectionId]),
            ];
        }

        // Root-level leaves: Dashboard, Chat (hardcoded — not viewers), then the
        // `_top` viewers (Došlá pošta, Úkoly) ordered by navOrder, then sections.
        // Výsledek: Dashboard → Chat → <_top> → <sekce…>.
        $groups = array_merge(array_map([$this, 'cleanItem'], $topLeaves), $tree);

        array_unshift($groups, [
            'id'    => 'chat',
            'label' => 'Chat',
            'type'  => 'chat',
            'icon'  => 'chat',
        ]);
        array_unshift($groups, [
            'id'    => 'dashboard',
            'label' => 'Dashboard',
            'type'  => 'dashboard',
            'icon'  => 'dashboard',
        ]);

        return Response::success($groups);
    }

    /**
     * Sebere všechny navigační itemy napříč moduly do plochého seznamu:
     * viditelné viewery (primární) + tabulky bez vieweru (generický fallback).
     * Ke každému připojí interní `_section` (navSection / fallback) a `_order`
     * (navOrder / velké číslo). Tyto klíče se z výstupu odstraní v cleanItem().
     *
     * @param  ModuleDefinition[]  $resolvedModules
     * @return array<int, array<string, mixed>>
     */
    private function collectItems(
        array $resolvedModules,
        ModulePathResolver $resolver,
        string $language,
        array $hiddenViewers,
        array $hiddenTables,
        array $tablesWithViewer,
    ): array {
        $items       = [];
        $seenViewers = [];
        $seenTables  = [];

        foreach ($resolvedModules as $module) {
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) {
                continue;
            }

            // Viewers — primary navigation entries.
            foreach ($module->viewers as $viewer) {
                $viewerId = $viewer['id'] ?? null;
                if ($viewerId === null || isset($hiddenViewers[$viewerId]) || isset($seenViewers[$viewerId])) {
                    continue;
                }
                $seenViewers[$viewerId] = true;

                $label = $viewer['name:' . $language]
                    ?? $viewer['name:en']
                    ?? $viewer['name']
                    ?? $viewerId;

                $item = [
                    'id'       => 'viewer:' . $viewerId,
                    'label'    => $label,
                    'type'     => 'viewer',
                    'viewerId' => $viewerId,
                    '_section' => isset($viewer['navSection']) ? (string) $viewer['navSection'] : self::FALLBACK_SECTION,
                    '_order'   => isset($viewer['navOrder']) ? (int) $viewer['navOrder'] : self::NAV_ORDER_DEFAULT,
                ];
                if (isset($viewer['icon'])) {
                    $item['icon'] = $viewer['icon'];
                }
                $items[] = $item;
            }

            // Tables not represented by any viewer (anywhere) — generic fallback
            // table items. navSection/navOrder may be declared in the table .jsonc.
            foreach ($module->tables as $tableName) {
                if ($this->shouldSkip($tableName)
                    || isset($hiddenTables[$tableName])
                    || isset($tablesWithViewer[$tableName])
                    || isset($seenTables[$tableName])
                ) {
                    continue;
                }
                $seenTables[$tableName] = true;

                $meta = $this->loadTableMeta($modulePath, $tableName, $language);

                $item = [
                    'id'       => $tableName,
                    'label'    => $meta['name'] ?? $tableName,
                    'type'     => 'table',
                    'table'    => $tableName,
                    '_section' => isset($meta['navSection']) ? (string) $meta['navSection'] : self::FALLBACK_SECTION,
                    '_order'   => isset($meta['navOrder']) ? (int) $meta['navOrder'] : self::NAV_ORDER_DEFAULT,
                ];
                if (isset($meta['icon'])) {
                    $item['icon'] = $meta['icon'];
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Načte sekce z cfgItem `global.navSections` (jako settingsSections), nebo
     * vestavěný fallback když compiled config chybí. Vrací seznam seřazený dle
     * `order`, s lokalizovaným `label`.
     *
     * @return array<int, array{id: string, label: string, icon: ?string, order: int}>
     */
    private function loadSections(?ConfigRuntime $configRuntime, string $language): array
    {
        $raw = $configRuntime?->cfgItem('global.navSections');
        if (is_array($raw) && !empty($raw['sections']) && is_array($raw['sections'])) {
            $sections = [];
            foreach ($raw['sections'] as $section) {
                if (!isset($section['id'])) {
                    continue;
                }
                $sections[] = [
                    'id'    => (string) $section['id'],
                    'label' => $section['name:' . $language]
                        ?? $section['name:en']
                        ?? $section['name']
                        ?? (string) $section['id'],
                    'icon'  => $section['icon'] ?? null,
                    'order' => isset($section['order']) ? (int) $section['order'] : 0,
                ];
            }
            usort($sections, fn($a, $b) => $a['order'] <=> $b['order']);
            return $sections;
        }

        // Fallback — compiled config missing. Degraded but functional.
        $sections = [];
        foreach (self::SECTIONS_FALLBACK as $section) {
            $sections[] = [
                'id'    => $section['id'],
                'label' => $section[$language] ?? $section['en'],
                'icon'  => null,
                'order' => $section['order'],
            ];
        }
        return $sections;
    }

    /** Seřadí itemy in-place dle interního `_order` (stabilní v PHP 8+). */
    private function sortItems(array &$items): void
    {
        usort(
            $items,
            fn($a, $b) => ($a['_order'] ?? self::NAV_ORDER_DEFAULT) <=> ($b['_order'] ?? self::NAV_ORDER_DEFAULT),
        );
    }

    /** Odstraní interní klíče (`_section`, `_order`) — nepatří do API výstupu. */
    private function cleanItem(array $item): array
    {
        unset($item['_section'], $item['_order']);
        return $item;
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

    private function shouldSkip(string $tableName): bool
    {
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (str_contains($tableName, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
