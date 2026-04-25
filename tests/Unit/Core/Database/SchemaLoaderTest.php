<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\SchemaLoader;

class SchemaLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $modulesPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-schema-loader-' . bin2hex(random_bytes(8));
        $this->modulesPath = $this->tmpDir . '/modules';
        mkdir($this->modulesPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeModule(string $id, array $tables = [], array $extensions = []): void
    {
        [$group, $name] = explode('.', $id, 2);
        $moduleDir = $this->modulesPath . '/' . $group . '/' . $name;
        mkdir($moduleDir . '/tables', 0755, true);
        if (!empty($extensions)) {
            mkdir($moduleDir . '/extensions', 0755, true);
        }

        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id' => $id,
            'name' => $name,
            'dependencies' => [],
            'tables' => array_keys($tables),
            'extensions' => array_keys($extensions),
            'config' => [],
        ]));

        foreach ($tables as $tableFile => $tableData) {
            file_put_contents($moduleDir . '/tables/' . $tableFile . '.jsonc', json_encode($tableData));
        }

        foreach ($extensions as $extFile => $extData) {
            file_put_contents($moduleDir . '/extensions/' . $extFile . '.jsonc', json_encode($extData));
        }
    }

    private function pkColumn(): array
    {
        return ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true, 'nullable' => false];
    }

    public function testLoadsTablesFromActiveModules(): void
    {
        $this->writeModule('test.unit', [
            'test_unit_items' => [
                'tableId' => 1,
                'name' => 'test_unit_items',
                'columns' => [
                    $this->pkColumn(),
                    ['id' => 'label', 'name' => 'Label', 'type' => 'varchar', 'length' => 100, 'nullable' => false],
                ],
            ],
        ]);

        $result = SchemaLoader::loadResolvedTables($this->modulesPath, ['test.unit']);

        $this->assertSame([], $result['errors']);
        $this->assertArrayHasKey('test_unit_items', $result['tables']);
        $this->assertCount(2, $result['tables']['test_unit_items']->columns);
    }

    public function testAppliesExtensionsAcrossModules(): void
    {
        $this->writeModule('test.base', [
            'test_base_items' => [
                'tableId' => 1,
                'name' => 'test_base_items',
                'columns' => [$this->pkColumn()],
            ],
        ]);

        $this->writeModule('test.ext', [], [
            'test_base_items' => [
                'table' => 'test_base_items',
                'columns' => [
                    ['id' => 'extra', 'name' => 'Extra', 'type' => 'varchar', 'length' => 50, 'nullable' => true],
                ],
            ],
        ]);

        $result = SchemaLoader::loadResolvedTables(
            $this->modulesPath,
            ['test.base', 'test.ext'],
        );

        $this->assertSame([], $result['errors']);
        $columnIds = array_map(fn($c) => $c->id, $result['tables']['test_base_items']->columns);
        $this->assertContains('extra', $columnIds);
    }

    public function testReturnsResolutionErrors(): void
    {
        $this->writeModule('test.unit', [
            'test_unit_items' => [
                'tableId' => 1,
                'name' => 'test_unit_items',
                'columns' => [$this->pkColumn()],
            ],
        ]);

        $result = SchemaLoader::loadResolvedTables(
            $this->modulesPath,
            ['nonexistent.module'],
        );

        $this->assertNotEmpty($result['errors']);
    }
}
