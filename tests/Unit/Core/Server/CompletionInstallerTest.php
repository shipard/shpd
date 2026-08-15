<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\CompletionInstaller;

class TestableCompletionInstaller extends CompletionInstaller
{
    /** @var array<string, string> name → path; missing = not in PATH */
    public array $binaryPaths = [];
    /** @var array<string, ?string> path → script; missing = generation fails */
    public array $scripts = [];

    protected function resolveBinaryPath(string $name): ?string
    {
        return $this->binaryPaths[$name] ?? null;
    }

    protected function generateScript(string $binaryPath): ?string
    {
        return $this->scripts[$binaryPath] ?? null;
    }
}

class CompletionInstallerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd-completion-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
    }

    private function makeInstaller(): TestableCompletionInstaller
    {
        $installer = new TestableCompletionInstaller($this->tempDir);
        $installer->binaryPaths = ['shpd-server' => '/usr/local/bin/shpd-server'];
        $installer->scripts = ['/usr/local/bin/shpd-server' => "# bash completion\ncomplete -F _shpd shpd-server\n"];
        return $installer;
    }

    public function testInstallWritesScript(): void
    {
        $installer = $this->makeInstaller();

        $result = $installer->install('shpd-server');

        $this->assertSame('installed', $result['status']);
        $target = $this->tempDir . '/shpd-server';
        $this->assertFileExists($target);
        $this->assertStringContainsString('complete -F _shpd', (string) file_get_contents($target));
        $this->assertSame(0644, fileperms($target) & 0777);
        $this->assertFileDoesNotExist($target . '.tmp');
    }

    public function testSecondRunIsUpToDate(): void
    {
        $installer = $this->makeInstaller();
        $installer->install('shpd-server');

        $result = $installer->install('shpd-server');

        $this->assertSame('up-to-date', $result['status']);
    }

    public function testChangedScriptIsRewritten(): void
    {
        $installer = $this->makeInstaller();
        $installer->install('shpd-server');
        $installer->scripts['/usr/local/bin/shpd-server'] = "# v2\n";

        $result = $installer->install('shpd-server');

        $this->assertSame('installed', $result['status']);
        $this->assertSame("# v2\n", file_get_contents($this->tempDir . '/shpd-server'));
    }

    public function testBinaryNotInPathIsSkipped(): void
    {
        $installer = $this->makeInstaller();

        $result = $installer->install('shpd-ds');

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('not found in PATH', $result['message']);
    }

    public function testGenerationFailureIsSkipped(): void
    {
        $installer = $this->makeInstaller();
        $installer->scripts = [];

        $result = $installer->install('shpd-server');

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('generation failed', $result['message']);
    }

    public function testMissingCompletionDirIsSkipped(): void
    {
        $installer = new TestableCompletionInstaller($this->tempDir . '/nonexistent');
        $installer->binaryPaths = ['shpd-server' => '/usr/local/bin/shpd-server'];
        $installer->scripts = ['/usr/local/bin/shpd-server' => "x\n"];

        $result = $installer->install('shpd-server');

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('does not exist', $result['message']);
    }
}
