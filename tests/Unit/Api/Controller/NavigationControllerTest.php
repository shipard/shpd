<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\NavigationController;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Hlavní navigace (sidebar) v app módu — sekce dle cfgItem global.navSections,
 * řazení dle navSections.order / navOrder, sentinel _top, fallback do system,
 * skrytí souhrnného docs.core.heads (sdílená tabulka faktur nepoškozena) a
 * mizení položek přesunutých do Nastavení. Reálné moduly z modules/, cfgItem
 * mockovaný (jako u SettingsControllerTest::testAccountNavigation…).
 */
class NavigationControllerTest extends TestCase
{
    private string $dsDir;
    private ModulePathResolver $resolver;
    private NavigationController $ctrl;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_navctrl_' . uniqid('', true);
        mkdir($this->dsDir . '/config', 0755, true);
        $this->resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $this->ctrl     = new NavigationController();
    }

    protected function tearDown(): void
    {
        @unlink($this->dsDir . '/config/main.json');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    // --- helpers ---

    /** @param string[] $modules */
    private function config(array $modules): DataSourceConfig
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'                => 'test-test-test-test',
            'name'              => 'Testovací firma',
            'database_name'     => 'x',
            'database_user'     => 'x',
            'database_password' => 'x',
            'created'           => '2026-01-01T00:00:00+00:00',
            'modules'           => $modules,
        ]));
        return new DataSourceConfig($this->dsDir);
    }

    /**
     * ConfigRuntime mock vracející REÁLNý global.navSections (lokalizovaný
     * stejně jako compiled config) — věrná simulace produkce.
     */
    private function configRuntime(string $language): ConfigRuntime
    {
        $raw       = JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/install/base/config/navSections.jsonc');
        $localized = ConfigLocalizer::localize($raw, $language);

        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(
            fn(string $id) => $id === 'global.navSections' ? $localized : null,
        );
        return $cfg;
    }

    /** @param string[] $modules @return array<int, array> the navigation tree */
    private function tree(array $modules, string $language = 'cs', ?ConfigRuntime $cfg = null): array
    {
        $resp = $this->ctrl->navigation(
            $this->config($modules),
            $this->resolver,
            $language,
            $cfg ?? $this->configRuntime($language),
        );
        $this->assertInstanceOf(Response::class, $resp);
        return $resp->getPayload()['data'];
    }

    /** Vrátí top-level node dle id (sekce nebo root leaf), nebo null. */
    private function node(array $tree, string $id): ?array
    {
        foreach ($tree as $n) {
            if (($n['id'] ?? null) === $id) {
                return $n;
            }
        }
        return null;
    }

    /** Rekurzivně sesbírá všechny viewerId v stromu. @return string[] */
    private function allViewerIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $n) {
            if (isset($n['viewerId'])) {
                $ids[] = $n['viewerId'];
            }
            if (!empty($n['children'])) {
                $ids = array_merge($ids, $this->allViewerIds($n['children']));
            }
        }
        return $ids;
    }

    /** Rekurzivně sesbírá všechny table ids v stromu. @return string[] */
    private function allTableIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $n) {
            if (($n['type'] ?? null) === 'table' && isset($n['table'])) {
                $ids[] = $n['table'];
            }
            if (!empty($n['children'])) {
                $ids = array_merge($ids, $this->allTableIds($n['children']));
            }
        }
        return $ids;
    }

    // --- full production layout ---

    public function testFullProductionTreeMatchesTargetLayout(): void
    {
        $tree = $this->tree(['install.base']);

        // Root-level order: Dashboard, Chat, Došlá pošta, Úkoly, then sections.
        $rootLabels = array_map(fn($n) => $n['label'], $tree);
        $this->assertSame(
            ['Dashboard', 'Chat', 'Došlá pošta', 'Spisovna', 'Úkoly', 'Základní', 'Nákup', 'Prodej', 'Účtárna', 'Systém'],
            $rootLabels,
        );

        // Dashboard + Chat are root leaves (type, no children).
        $this->assertSame('dashboard', $tree[0]['type']);
        $this->assertSame('chat', $tree[1]['type']);

        // _top viewers are root leaves carrying viewerId, ordered by navOrder.
        $this->assertSame('core.mail.incoming', $tree[2]['viewerId']);
        $this->assertSame('viewer', $tree[2]['type']);
        $this->assertSame('base.registry.documents', $tree[3]['viewerId']);
        $this->assertSame('tasks.core', $tree[4]['viewerId']);

        // Sections in navSections.order with the right children/order.
        $this->assertSame(
            ['base.persons', 'economy.items'],
            array_column($this->node($tree, 'basic')['children'], 'viewerId'),
        );
        $this->assertSame(
            ['docs.invoicesIn.heads'],
            array_column($this->node($tree, 'purchase')['children'], 'viewerId'),
        );
        $this->assertSame(
            ['docs.invoicesOut.heads'],
            array_column($this->node($tree, 'sales')['children'], 'viewerId'),
        );
        $this->assertSame(
            ['docs.accountingDocs.heads', 'economy.accounting.journal', 'economy.accounting.accounts', 'economy.bank.transactions', 'economy.accbal.ledger', 'economy.bank.statements'],
            array_column($this->node($tree, 'accounting')['children'], 'viewerId'),
        );
        // System holds ONLY Alerts — users/settings moved to Settings app.
        $this->assertSame(
            ['core.alerts.alerts'],
            array_column($this->node($tree, 'system')['children'], 'viewerId'),
        );
    }

    public function testSectionLabelsLocalizedEn(): void
    {
        $tree = $this->tree(['install.base'], 'en');
        $rootLabels = array_map(fn($n) => $n['label'], $tree);
        $this->assertSame(
            ['Dashboard', 'Chat', 'Incoming messages', 'Registry', 'Tasks', 'Basic', 'Purchase', 'Sales', 'Accounting', 'System'],
            $rootLabels,
        );
    }

    // --- shared table docs_core_heads ---

    public function testDocsHeadsHiddenButInvoicesVisible(): void
    {
        $tree     = $this->tree(['install.base']);
        $viewerIds = $this->allViewerIds($tree);

        // Summary viewer hidden…
        $this->assertNotContains('docs.core.heads', $viewerIds);
        // …but both per-type invoice viewers (sharing docs_core_heads) remain.
        $this->assertContains('docs.invoicesIn.heads', $viewerIds);
        $this->assertContains('docs.invoicesOut.heads', $viewerIds);

        // The shared table never leaks back as a raw fallback table item.
        $this->assertNotContains('docs_core_heads', $this->allTableIds($tree));
    }

    // --- items moved to Settings are gone from the main nav ---

    public function testMovedItemsAbsentFromNavigation(): void
    {
        $tableIds = $this->allTableIds($this->tree(['install.base']));

        foreach ([
            'core_mail_extracted_documents',
            'core_chat_messages',
            'economy_codebooks_vat_periods',
            'core_system_users',
            'core_system_settings',
        ] as $moved) {
            $this->assertNotContains($moved, $tableIds, "Moved-to-settings table leaked into nav: {$moved}");
        }
    }

    // --- empty sections are omitted ---

    public function testEmptySectionsOmitted(): void
    {
        // base.persons pulls core.alerts (→ system/Upozornění) as a dependency,
        // but no purchase/sales/accounting viewers — those sections vanish.
        $tree = $this->tree(['base.persons']);
        $ids  = array_map(fn($n) => $n['id'], $tree);

        $this->assertContains('basic', $ids);
        $this->assertContains('system', $ids);
        $this->assertNotContains('purchase', $ids);
        $this->assertNotContains('sales', $ids);
        $this->assertNotContains('accounting', $ids);
    }

    // --- fallback: viewer without navSection → system ---

    public function testViewerWithoutNavSectionFallsBackToSystem(): void
    {
        $modRoot = $this->makeFallbackFixtureModule();
        $resolver = new ModulePathResolver([$modRoot, dirname(__DIR__, 4) . '/modules']);

        $resp = $this->ctrl->navigation(
            $this->config(['test.navfallback']),
            $resolver,
            'cs',
            $this->configRuntime('cs'),
        );
        $tree = $resp->getPayload()['data'];

        $system = $this->node($tree, 'system');
        $this->assertNotNull($system, 'system section should exist');
        $this->assertContains('test.navfallback.viewer', array_column($system['children'], 'viewerId'));

        $this->cleanupFixtureModule($modRoot);
    }

    // --- fallback sections when compiled config is missing ---

    public function testFallbackSectionsWhenConfigRuntimeNull(): void
    {
        // configRuntime === null → controller uses its built-in PHP
        // SECTIONS_FALLBACK (degraded but functional, no crash). Call the
        // controller directly so the tree() helper does not substitute a mock.
        $resp = $this->ctrl->navigation($this->config(['install.base']), $this->resolver, 'cs', null);
        $tree = $resp->getPayload()['data'];
        $ids  = array_map(fn($n) => $n['id'], $tree);

        $this->assertSame(
            ['dashboard', 'chat', 'viewer:core.mail.incoming', 'viewer:base.registry.documents', 'viewer:tasks.core', 'basic', 'purchase', 'sales', 'accounting', 'system'],
            $ids,
        );
        // Fallback labels come from the PHP const, localized by $language.
        $this->assertSame('Základní', $this->node($tree, 'basic')['label']);
        $this->assertSame('Účtárna', $this->node($tree, 'accounting')['label']);
    }

    public function testFallbackSectionsEnglishWhenConfigRuntimeNull(): void
    {
        $resp = $this->ctrl->navigation($this->config(['install.base']), $this->resolver, 'en', null);
        $tree = $resp->getPayload()['data'];
        $this->assertSame('Accounting', $this->node($tree, 'accounting')['label']);
    }

    // --- API shape unchanged ---

    public function testApiShapeUnchanged(): void
    {
        $tree = $this->tree(['install.base']);

        // Root leaf: type + icon, no children.
        $this->assertSame('dashboard', $tree[0]['type']);
        $this->assertArrayHasKey('icon', $tree[0]);
        $this->assertArrayNotHasKey('children', $tree[0]);

        // Section: id + label + children; no internal keys leak out.
        $basic = $this->node($tree, 'basic');
        $this->assertArrayHasKey('children', $basic);
        $this->assertArrayNotHasKey('_section', $basic['children'][0]);
        $this->assertArrayNotHasKey('_order', $basic['children'][0]);

        // Viewer child: id 'viewer:<id>', type, viewerId.
        $this->assertSame('viewer:base.persons', $basic['children'][0]['id']);
        $this->assertSame('viewer', $basic['children'][0]['type']);
        $this->assertSame('base.persons', $basic['children'][0]['viewerId']);
    }

    // --- fixture helpers ---

    /** Creates a temp module root with a viewer that has NO navSection. */
    private function makeFallbackFixtureModule(): string
    {
        $root = sys_get_temp_dir() . '/shpd_navfix_' . uniqid('', true);
        $dir  = $root . '/test/navfallback';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', json_encode([
            'id'           => 'test.navfallback',
            'name'         => 'Nav fallback fixture',
            'dependencies' => [],
            'tables'       => ['test_navfallback_things'],
            'viewers'      => [[
                'id'    => 'test.navfallback.viewer',
                'name'  => 'Things',
                'icon'  => 'box',
                'table' => 'test_navfallback_things',
                'class' => 'Acme\\Nope',
                // intentionally NO navSection / navOrder
            ]],
        ]));
        return $root;
    }

    private function cleanupFixtureModule(string $root): void
    {
        @unlink($root . '/test/navfallback/module.jsonc');
        @rmdir($root . '/test/navfallback');
        @rmdir($root . '/test');
        @rmdir($root);
    }
}
