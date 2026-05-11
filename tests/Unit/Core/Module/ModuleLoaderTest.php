<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;

class ModuleLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    public function testLoadModuleValid(): void
    {
        $moduleDir = $this->tmpDir . '/economy/docs';
        mkdir($moduleDir, 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id' => 'economy.docs',
            'name' => 'Documents',
            'dependencies' => ['core.system'],
        ]));

        $def = ModuleLoader::loadModule($moduleDir);

        $this->assertInstanceOf(ModuleDefinition::class, $def);
        $this->assertSame('economy.docs', $def->id);
        $this->assertSame('Documents', $def->name);
        $this->assertSame(['core.system'], $def->dependencies);
    }

    public function testLoadModuleMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        ModuleLoader::loadModule($this->tmpDir . '/nonexistent/module');
    }

    public function testLoadAllModules(): void
    {
        $dir1 = $this->tmpDir . '/core/system';
        $dir2 = $this->tmpDir . '/base/persons';
        mkdir($dir1, 0755, true);
        mkdir($dir2, 0755, true);

        file_put_contents($dir1 . '/module.jsonc', json_encode(['id' => 'core.system', 'name' => 'System']));
        file_put_contents($dir2 . '/module.jsonc', json_encode(['id' => 'base.persons', 'name' => 'Persons']));

        $modules = ModuleLoader::loadAllModules(new ModulePathResolver([$this->tmpDir]));

        $this->assertCount(2, $modules);
        $ids = array_map(fn($m) => $m->id, $modules);
        $this->assertContains('core.system', $ids);
        $this->assertContains('base.persons', $ids);
    }

    public function testLoadAllModulesSkipsMissingJsonc(): void
    {
        $dir1 = $this->tmpDir . '/core/system';
        $dir2 = $this->tmpDir . '/base/persons';
        mkdir($dir1, 0755, true);
        mkdir($dir2, 0755, true);

        // Only dir1 has module.jsonc
        file_put_contents($dir1 . '/module.jsonc', json_encode(['id' => 'core.system', 'name' => 'System']));

        $modules = ModuleLoader::loadAllModules(new ModulePathResolver([$this->tmpDir]));

        $this->assertCount(1, $modules);
        $this->assertSame('core.system', $modules[0]->id);
    }
}
