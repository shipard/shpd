<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\CompletionInstallCommand;
use Shipard\Core\Server\CompletionInstaller;
use Symfony\Component\Console\Tester\CommandTester;

class StubCompletionInstaller extends CompletionInstaller
{
    /** @var array<string, array{status: string, message: string}> */
    public array $results = [];
    /** @var list<string> */
    public array $installedBinaries = [];

    public function install(string $binaryName): array
    {
        $this->installedBinaries[] = $binaryName;
        return $this->results[$binaryName]
            ?? ['status' => 'up-to-date', 'message' => '/etc/bash_completion.d/' . $binaryName];
    }
}

class TestableCompletionInstallCommand extends CompletionInstallCommand
{
    public int $euid = 0;
    public StubCompletionInstaller $installer;

    public function __construct()
    {
        parent::__construct();
        $this->installer = new StubCompletionInstaller();
    }

    protected function getEuid(): int
    {
        return $this->euid;
    }

    protected function createInstaller(): CompletionInstaller
    {
        return $this->installer;
    }
}

class CompletionInstallCommandTest extends TestCase
{
    public function testRequiresRoot(): void
    {
        $command = new TestableCompletionInstallCommand();
        $command->euid = 1000;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must run as root', $tester->getDisplay());
        $this->assertSame([], $command->installer->installedBinaries);
    }

    public function testInstallsBothBinaries(): void
    {
        $command = new TestableCompletionInstallCommand();
        $command->installer->results = [
            'shpd-server' => ['status' => 'installed', 'message' => '/etc/bash_completion.d/shpd-server'],
        ];

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['shpd-server', 'shpd-ds'], $command->installer->installedBinaries);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('/etc/bash_completion.d/shpd-server written', $display);
        $this->assertStringContainsString('/etc/bash_completion.d/shpd-ds up to date', $display);
    }

    public function testSkippedBinaryWarnsButSucceeds(): void
    {
        $command = new TestableCompletionInstallCommand();
        $command->installer->results = [
            'shpd-ds' => ['status' => 'skipped', 'message' => 'shpd-ds not found in PATH'],
        ];

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[WARN] shpd-ds not found in PATH', $tester->getDisplay());
    }

    public function testWriteErrorFails(): void
    {
        $command = new TestableCompletionInstallCommand();
        $command->installer->results = [
            'shpd-server' => ['status' => 'error', 'message' => 'cannot write /etc/bash_completion.d/shpd-server.tmp'],
        ];

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] cannot write', $tester->getDisplay());
    }
}
