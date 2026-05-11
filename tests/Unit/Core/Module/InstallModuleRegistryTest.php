<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\InstallModuleRegistry;

class InstallModuleRegistryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-reg-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
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
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createInstallModule(string $name, array $extra = []): void
    {
        $dir = $this->tmpDir . '/install/' . $name;
        mkdir($dir, 0755, true);

        $data = array_merge([
            'id'          => 'install.' . $name,
            'name'        => 'Module ' . $name,
            'description' => 'Description for ' . $name,
        ], $extra);

        file_put_contents(
            $dir . '/module.jsonc',
            (string) json_encode($data, JSON_PRETTY_PRINT),
        );
    }

    public function testEmptyModulesDirReturnsEmpty(): void
    {
        $reg = new InstallModuleRegistry($this->tmpDir);
        $this->assertSame([], $reg->list());
        $this->assertFalse($reg->exists('install.base'));
    }

    public function testInstallDirWithoutModules(): void
    {
        mkdir($this->tmpDir . '/install', 0755, true);
        $reg = new InstallModuleRegistry($this->tmpDir);
        $this->assertSame([], $reg->list());
    }

    public function testListReturnsInstallModules(): void
    {
        $this->createInstallModule('base');
        $this->createInstallModule('foo');
        $this->createInstallModule('bar');

        $reg = new InstallModuleRegistry($this->tmpDir);
        $list = $reg->list();

        $this->assertCount(3, $list);
        foreach ($list as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('description', $item);
        }
    }

    public function testListSkipsMalformedModuleJsonc(): void
    {
        $this->createInstallModule('base');

        $badDir = $this->tmpDir . '/install/broken';
        mkdir($badDir, 0755, true);
        file_put_contents($badDir . '/module.jsonc', '{ this is not valid json');

        $reg = new InstallModuleRegistry($this->tmpDir);
        $list = $reg->list();

        $this->assertCount(1, $list);
        $this->assertSame('install.base', $list[0]['id']);
    }

    public function testListSkipsDirsWithoutModuleJsonc(): void
    {
        $this->createInstallModule('base');
        mkdir($this->tmpDir . '/install/empty-dir', 0755, true);

        $reg = new InstallModuleRegistry($this->tmpDir);
        $list = $reg->list();

        $this->assertCount(1, $list);
        $this->assertSame('install.base', $list[0]['id']);
    }

    public function testListSortsByNameCaseInsensitive(): void
    {
        $this->createInstallModule('zebra', ['name' => 'Zebra']);
        $this->createInstallModule('alpha', ['name' => 'alpha']);
        $this->createInstallModule('bravo', ['name' => 'Bravo']);

        $reg = new InstallModuleRegistry($this->tmpDir);
        $names = array_map(fn(array $m): string => $m['name'], $reg->list());

        $this->assertSame(['alpha', 'Bravo', 'Zebra'], $names);
    }

    public function testExistsReturnsTrueForValidId(): void
    {
        $this->createInstallModule('base');
        $reg = new InstallModuleRegistry($this->tmpDir);
        $this->assertTrue($reg->exists('install.base'));
    }

    public function testExistsReturnsFalseForMissingId(): void
    {
        $reg = new InstallModuleRegistry($this->tmpDir);
        $this->assertFalse($reg->exists('install.nonexistent'));
    }

    public function testExistsRejectsInvalidFormat(): void
    {
        $this->createInstallModule('base');
        $reg = new InstallModuleRegistry($this->tmpDir);

        $this->assertFalse($reg->exists('core.system'));
        $this->assertFalse($reg->exists('install.'));
        $this->assertFalse($reg->exists('install.Bad'));
        $this->assertFalse($reg->exists(''));
    }
}
