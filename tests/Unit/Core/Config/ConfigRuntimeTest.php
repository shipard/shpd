<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;

class ConfigRuntimeTest extends TestCase
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

    private function writeCompiledConfig(string $language, array $items): void
    {
        $dir = $this->tmpDir . '/config/configuration';
        mkdir($dir, 0755, true);
        $data = ['_meta' => ['language' => $language], 'items' => $items];
        file_put_contents($dir . '/compiled.' . $language . '.json', json_encode($data));
    }

    public function testCfgItemExisting(): void
    {
        $this->writeCompiledConfig('en', [
            'core.app' => ['name' => 'App', 'debug' => false],
        ]);

        $runtime = ConfigRuntime::load($this->tmpDir, 'en');
        $item = $runtime->cfgItem('core.app');

        $this->assertIsArray($item);
        $this->assertSame('App', $item['name']);
        $this->assertFalse($item['debug']);
    }

    public function testCfgItemMissing(): void
    {
        $this->writeCompiledConfig('en', []);

        $runtime = ConfigRuntime::load($this->tmpDir, 'en');
        $this->assertNull($runtime->cfgItem('nonexistent'));
    }

    public function testLoadMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ConfigRuntime::load($this->tmpDir, 'en');
    }
}
