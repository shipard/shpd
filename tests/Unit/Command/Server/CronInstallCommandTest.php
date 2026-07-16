<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\CronInstallCommand;
use Shipard\Core\Server\CronProvisioner;
use Symfony\Component\Console\Tester\CommandTester;

class TestableCronInstallCommand extends CronInstallCommand
{
    /** @var list<array{path: string, user: string}> */
    public array $ownershipLog = [];

    public function __construct(
        private readonly string $cronFilePath,
        private readonly string $runDir,
        private readonly int $euid = 0,
    ) {
        parent::__construct();
    }

    protected function getCronFilePath(): string
    {
        return $this->cronFilePath;
    }

    protected function getRunDir(): string
    {
        return $this->runDir;
    }

    protected function getRepoRoot(): string
    {
        return '/opt/shipard/shpd';
    }

    protected function getPhpBinary(): string
    {
        return '/usr/bin/php8.5';
    }

    protected function getEuid(): int
    {
        return $this->euid;
    }

    protected function getServerMode(): string
    {
        return 'production';
    }

    protected function applyOwnership(string $path, string $user): void
    {
        $this->ownershipLog[] = ['path' => $path, 'user' => $user];
    }
}

class CronInstallCommandTest extends TestCase
{
    private string $tmpDir;
    private string $cronFile;
    private string $runDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-croninstall-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->cronFile = $this->tmpDir . '/cron.d/shipard';
        mkdir($this->tmpDir . '/cron.d', 0755, true);
        $this->runDir = $this->tmpDir . '/run';
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    /** @return array{TestableCronInstallCommand, CommandTester} */
    private function makeTester(int $euid = 0): array
    {
        $cmd = new TestableCronInstallCommand($this->cronFile, $this->runDir, $euid);
        return [$cmd, new CommandTester($cmd)];
    }

    public function testFreshInstallCreatesFileAndRunDir(): void
    {
        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->cronFile);
        $content = (string) file_get_contents($this->cronFile);
        $this->assertSame(CronProvisioner::TEMPLATE_VERSION, CronProvisioner::parseTemplateVersion($content));
        foreach (CronProvisioner::SLOTS as $slot) {
            $this->assertStringContainsString('shpd-server cron --slot=' . $slot, $content);
        }
        $this->assertStringContainsString('shipard /usr/bin/php8.5 /opt/shipard/shpd/bin/shpd-server', $content);

        $this->assertDirectoryExists($this->runDir);
        $this->assertSame(0750, fileperms($this->runDir) & 0777);
        $this->assertSame([['path' => $this->runDir, 'user' => 'shipard']], $cmd->ownershipLog);
        $this->assertStringContainsString('written (template version', $tester->getDisplay());
    }

    public function testSecondRunIsIdempotent(): void
    {
        [, $tester1] = $this->makeTester();
        $tester1->execute([]);
        $mtime = filemtime($this->cronFile);
        touch($this->cronFile, $mtime - 100);
        clearstatcache();
        $mtime = filemtime($this->cronFile);

        [, $tester2] = $this->makeTester();
        $exit = $tester2->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('up to date', $tester2->getDisplay());
        clearstatcache();
        $this->assertSame($mtime, filemtime($this->cronFile));
    }

    public function testTamperedFileIsRewritten(): void
    {
        file_put_contents($this->cronFile, "# template version 0 — old\n* * * * * root /bin/true\n");

        [, $tester] = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('written (template version', $tester->getDisplay());
        $content = (string) file_get_contents($this->cronFile);
        $this->assertSame(CronProvisioner::TEMPLATE_VERSION, CronProvisioner::parseTemplateVersion($content));
        $this->assertStringNotContainsString('/bin/true', $content);
    }

    public function testNonRootFails(): void
    {
        [, $tester] = $this->makeTester(euid: 1000);

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must run as root', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->cronFile);
    }

    public function testDryRunWritesNothing(): void
    {
        [, $tester] = $this->makeTester(euid: 1000);

        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertFileDoesNotExist($this->cronFile);
        $this->assertDirectoryDoesNotExist($this->runDir);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Would create the file', $display);
        $this->assertStringContainsString('cron --slot=minute', $display);
    }

    public function testDryRunReportsUpToDate(): void
    {
        [, $tester1] = $this->makeTester();
        $tester1->execute([]);

        [, $tester2] = $this->makeTester(euid: 1000);
        $exit = $tester2->execute(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Up to date', $tester2->getDisplay());
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
