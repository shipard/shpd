<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Utils\JsoncParser;

/**
 * Guards declarative contracts of hosting.core / install.hosting, na kterých
 * závisí runtime: `adminOnly` na všech hosting tabulkách (D9 — bez něj by
 * generické CRUD/viewer/form cesty pustily ne-adminy k evidenci hostingu)
 * a minimální závislosti dedikovaného install modulu (D11).
 */
class HostingModuleDefinitionTest extends TestCase
{
    private const MODULE_PATH = '/modules/hosting/core';

    public function testModuleDeclaresThreeTables(): void
    {
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . self::MODULE_PATH);

        $this->assertSame('hosting.core', $module->id);
        $this->assertSame(
            ['hosting_core_servers', 'hosting_core_data_sources', 'hosting_core_ds_users'],
            $module->tables,
        );
    }

    public function testAllTablesAreAdminOnly(): void
    {
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . self::MODULE_PATH);

        foreach ($module->tables as $tableFile) {
            $raw = JsoncParser::parseFile(
                dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/' . $tableFile . '.jsonc',
            );
            $def = TableDefinition::fromArray($raw);

            $this->assertTrue($def->adminOnly, "Tabulka {$tableFile} musí mít adminOnly=true (D9)");
        }
    }

    public function testDataSourcesTableShape(): void
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/hosting_core_data_sources.jsonc',
        );
        $def = TableDefinition::fromArray($raw);

        $columnIds = array_map(static fn ($c) => $c->id, $def->columns);
        foreach (['ds_id', 'name', 'web_id', 'server', 'url_app', 'install_module', 'lifecycle'] as $expected) {
            $this->assertContains($expected, $columnIds);
        }

        $uniqueIndexes = [];
        foreach ($def->indexes as $index) {
            if ($index->type === 'unique') {
                $uniqueIndexes[] = $index->id;
            }
        }
        $this->assertContains('unq_ds_id', $uniqueIndexes);
        $this->assertContains('unq_web_id', $uniqueIndexes);
    }

    public function testInstallHostingHasMinimalDependencies(): void
    {
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . '/modules/install/hosting');

        $this->assertSame('install.hosting', $module->id);
        $this->assertSame(['core.system', 'core.alerts', 'hosting.core'], $module->dependencies);
    }
}
