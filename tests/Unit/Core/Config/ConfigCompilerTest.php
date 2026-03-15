<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Module\ModuleDefinition;

class ConfigCompilerTest extends TestCase
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

    private function makeModule(string $id, array $config): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id' => $id,
            'name' => $id,
            'config' => $config,
        ]);
    }

    private function writeConfigFile(string $modulePath, string $relPath, array $data): void
    {
        $fullPath = $modulePath . '/' . $relPath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, json_encode($data));
    }

    public function testSingleModuleSingleLanguage(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App', 'debug' => false]);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], $this->tmpDir . '/modules', ['en'], $outputPath);

        $this->assertFileExists($outputPath . '/compiled.en.json');
        $data = json_decode(file_get_contents($outputPath . '/compiled.en.json'), true);
        $this->assertArrayHasKey('core.app', $data['items']);
        $this->assertSame('App', $data['items']['core.app']['name']);
    }

    public function testMultipleModulesMultipleLanguages(): void
    {
        $mod1Path = $this->tmpDir . '/modules/core/system';
        $mod2Path = $this->tmpDir . '/modules/economy/docs';
        $this->writeConfigFile($mod1Path, 'config/sys.jsonc', ['name' => 'System']);
        $this->writeConfigFile($mod2Path, 'config/docs.jsonc', ['name' => 'Docs']);

        $modules = [
            $this->makeModule('core.system', [['id' => 'core.sys', 'file' => 'config/sys.jsonc']]),
            $this->makeModule('economy.docs', [['id' => 'economy.docs', 'file' => 'config/docs.jsonc']]),
        ];
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile($modules, $this->tmpDir . '/modules', ['cs', 'en'], $outputPath);

        $this->assertFileExists($outputPath . '/compiled.cs.json');
        $this->assertFileExists($outputPath . '/compiled.en.json');

        foreach (['cs', 'en'] as $lang) {
            $data = json_decode(file_get_contents($outputPath . "/compiled.$lang.json"), true);
            $this->assertArrayHasKey('core.sys', $data['items']);
            $this->assertArrayHasKey('economy.docs', $data['items']);
        }
    }

    public function testLocalizationCs(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', [
            'name' => 'App',
            'name:cs' => 'Aplikace',
            'name:en' => 'Application',
        ]);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], $this->tmpDir . '/modules', ['cs'], $outputPath);

        $data = json_decode(file_get_contents($outputPath . '/compiled.cs.json'), true);
        $item = $data['items']['core.app'];
        $this->assertSame('Aplikace', $item['name']);
        $this->assertArrayNotHasKey('name:cs', $item);
    }

    public function testLocalizationEn(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', [
            'name' => 'App',
            'name:cs' => 'Aplikace',
            'name:en' => 'Application',
        ]);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], $this->tmpDir . '/modules', ['en'], $outputPath);

        $data = json_decode(file_get_contents($outputPath . '/compiled.en.json'), true);
        $item = $data['items']['core.app'];
        $this->assertSame('Application', $item['name']);
        $this->assertArrayNotHasKey('name:en', $item);
    }

    public function testFallbackMissingTranslation(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], $this->tmpDir . '/modules', ['cs', 'en'], $outputPath);

        foreach (['cs', 'en'] as $lang) {
            $data = json_decode(file_get_contents($outputPath . "/compiled.$lang.json"), true);
            $this->assertSame('App', $data['items']['core.app']['name']);
        }
    }

    public function testMetaSection(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], $this->tmpDir . '/modules', ['en'], $outputPath);

        $data = json_decode(file_get_contents($outputPath . '/compiled.en.json'), true);
        $meta = $data['_meta'];
        $this->assertArrayHasKey('compiled', $meta);
        $this->assertSame('0.1.0', $meta['version']);
        $this->assertSame('en', $meta['language']);
        $this->assertSame(['core.system'], $meta['modules']);
    }

    public function testOutputDirCreated(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/new/nested/output';

        $this->assertDirectoryDoesNotExist($outputPath);

        ConfigCompiler::compile([$module], $this->tmpDir . '/modules', ['en'], $outputPath);

        $this->assertDirectoryExists($outputPath);
        $this->assertFileExists($outputPath . '/compiled.en.json');
    }
}
