<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DsStateCheckCommand;
use Shipard\Core\Logging\ErrorLogger;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsStateCheckCommand extends DsStateCheckCommand
{
    public function __construct(
        private readonly string $dsDir,
        private readonly ?string $logPath,
        private readonly \DateTimeImmutable $fakeNow,
    ) {
        parent::__construct();
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dsDir;
    }

    protected function getLogPath(): ?string
    {
        return $this->logPath;
    }

    protected function now(): \DateTimeImmutable
    {
        return $this->fakeNow;
    }
}

class DsStateCheckCommandTest extends TestCase
{
    private string $tmpDir;
    private string $dsDir;
    private string $logPath;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_ds_state_check_' . uniqid();
        $this->dsDir = $this->tmpDir . '/data-sources';
        $this->logPath = $this->tmpDir . '/shipard.log';
        mkdir($this->dsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
        ErrorLogger::resetForTesting();
    }

    private function createDs(string $id, ?string $stateJson = null): void
    {
        mkdir($this->dsDir . '/' . $id . '/config', 0755, true);
        file_put_contents($this->dsDir . '/' . $id . '/config/main.json', '{}');
        if ($stateJson !== null) {
            file_put_contents($this->dsDir . '/' . $id . '/config/state.json', $stateJson);
        }
    }

    private function runCheck(): array
    {
        $cmd = new TestableDsStateCheckCommand($this->dsDir, $this->logPath, new \DateTimeImmutable('2026-09-10T12:00:00Z'));
        $tester = new CommandTester($cmd);
        $exit = $tester->execute([]);
        return [$exit, $tester->getDisplay(), is_file($this->logPath) ? (string) file_get_contents($this->logPath) : ''];
    }

    public function testOverdueMaintenanceLogsWarning(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa',
            '{"version":1,"state":"active","maintenance":{"reason":"restore","since":"2026-08-30T12:00:00Z"}}');
        $this->createDs('bbbb-bbbb-bbbb-bbbb',
            '{"version":1,"state":"active","maintenance":{"reason":"import","since":"2026-09-09T12:00:00Z"}}');
        $this->createDs('cccc-cccc-cccc-cccc');

        [$exit, $display, $log] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('⚠ aaaa-aaaa-aaaa-aaaa: maintenance (restore) for 11 days', $display);
        $this->assertStringNotContainsString('bbbb-bbbb-bbbb-bbbb', $display);
        $this->assertStringContainsString('3 data source(s) scanned, 1 finding(s).', $display);
        $this->assertStringContainsString('maintenance longer than threshold', $log);
        $this->assertStringContainsString('aaaa-aaaa-aaaa-aaaa', $log);
        $this->assertStringNotContainsString('bbbb-bbbb-bbbb-bbbb', $log);
    }

    public function testCorruptedStateFileIsReported(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{broken');

        [$exit, $display, $log] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('✗ aaaa-aaaa-aaaa-aaaa: state.json unusable', $display);
        $this->assertStringContainsString('fail-closed', $log);
    }

    public function testNothingToReport(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        [$exit, $display, $log] = $this->runCheck();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 data source(s) scanned, 0 finding(s).', $display);
        $this->assertStringNotContainsString('maintenance longer', $log);
    }
}
