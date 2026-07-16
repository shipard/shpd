<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\CronCommand;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\CronProvisioner;
use Symfony\Component\Console\Tester\CommandTester;

class TestableCronCommand extends CronCommand
{
    /** @var array<string, array{exitCode: int, timedOut: bool, output: string}> klíč "dsId job" */
    private array $jobResults = [];

    /** @var list<array{ds: string, job: string}> */
    public array $callLog = [];

    public function __construct(
        private readonly string $dataSourcesDir,
        private readonly string $runDir,
        private readonly ?string $logPath,
    ) {
        parent::__construct();
    }

    /** @param array<string, array{exitCode: int, timedOut: bool, output: string}> $results */
    public function setJobResults(array $results): void
    {
        $this->jobResults = $results;
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function getRunDir(): string
    {
        return $this->runDir;
    }

    protected function getLogPath(): ?string
    {
        return $this->logPath;
    }

    protected function runJob(string $dsDir, string $job): array
    {
        $id = basename($dsDir);
        $this->callLog[] = ['ds' => $id, 'job' => $job];
        return $this->jobResults[$id . ' ' . $job]
            ?? ['exitCode' => 0, 'timedOut' => false, 'output' => ''];
    }
}

/** Timeout test potřebuje reálný runJob s podvrženou binárkou a krátkým limitem. */
class TimeoutCronCommand extends CronCommand
{
    public function __construct(private readonly string $shpdDsPath)
    {
        parent::__construct();
    }

    protected function getShpdDsPath(): string
    {
        return $this->shpdDsPath;
    }

    protected function getJobTimeoutSeconds(): int
    {
        return 1;
    }

    /** @return array{exitCode: int, timedOut: bool, output: string} */
    public function runJobPublic(string $dsDir, string $job): array
    {
        return $this->runJob($dsDir, $job);
    }
}

class CronCommandTest extends TestCase
{
    private string $tmpDir;
    private string $dsDir;
    private string $runDir;
    private string $logPath;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-cron-test-' . uniqid();
        $this->dsDir = $this->tmpDir . '/data-sources';
        $this->runDir = $this->tmpDir . '/run';
        $this->logPath = $this->tmpDir . '/shipard.log';
        mkdir($this->dsDir, 0755, true);
        mkdir($this->runDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        ErrorLogger::resetForTesting();
    }

    private function createDs(string $id): string
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        file_put_contents($dir . '/config/main.json', '{}');
        return $dir;
    }

    /** @return array{TestableCronCommand, CommandTester} */
    private function makeTester(): array
    {
        $cmd = new TestableCronCommand($this->dsDir, $this->runDir, $this->logPath);
        return [$cmd, new CommandTester($cmd)];
    }

    /** @return array<string, mixed> */
    private function readHeartbeat(string $slot): array
    {
        $path = CronProvisioner::heartbeatPath($slot, $this->runDir);
        $this->assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);
        return $data;
    }

    public function testMissingSlotFailsWithoutHeartbeat(): void
    {
        [, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown or missing --slot', $tester->getDisplay());
        $this->assertSame([], glob($this->runDir . '/*.heartbeat') ?: []);
    }

    public function testUnknownSlotFailsAndListsValidSlots(): void
    {
        [, $tester] = $this->makeTester();

        $exit = $tester->execute(['--slot' => 'hourly']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('minute, five-minutes, daily, weekly', $tester->getDisplay());
    }

    public function testEmptyDsListSucceedsAndWritesHeartbeat(): void
    {
        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertSame([], $cmd->callLog);
        $hb = $this->readHeartbeat('minute');
        $this->assertSame(0, $hb['dsCount']);
        $this->assertSame(0, $hb['failedCount']);
    }

    public function testSlotMapDispatchesExpectedJobsInSortedDsOrder(): void
    {
        $this->createDs('bbbb-bbbb-bbbb-bbbb');
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertSame([
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-outbox-run'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-outbox-run'],
        ], $cmd->callLog);
    }

    public function testSlotJobsMapping(): void
    {
        $this->assertSame(['mail-outbox-run'], CronCommand::SLOT_JOBS['minute']);
        $this->assertSame(['alerts-run'], CronCommand::SLOT_JOBS['five-minutes']);
        $this->assertSame(['mail-idempotency-prune'], CronCommand::SLOT_JOBS['daily']);
        $this->assertSame(['alerts-prune'], CronCommand::SLOT_JOBS['weekly']);
    }

    public function testFailedJobContinuesAndExitsSuccess(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');

        [$cmd, $tester] = $this->makeTester();
        $cmd->setJobResults([
            'aaaa-aaaa-aaaa-aaaa mail-outbox-run' => ['exitCode' => 1, 'timedOut' => false, 'output' => 'boom'],
        ]);

        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertCount(2, $cmd->callLog);

        $hb = $this->readHeartbeat('minute');
        $this->assertSame(1, $hb['failedCount']);
        $this->assertSame(2, $hb['jobsRun']);
        $this->assertSame('aaaa-aaaa-aaaa-aaaa', $hb['failures'][0]['ds']);
        $this->assertSame('mail-outbox-run', $hb['failures'][0]['job']);
        $this->assertSame(1, $hb['failures'][0]['exitCode']);

        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('cron job failed', $log);
        $this->assertStringContainsString('boom', $log);
    }

    public function testLockHeldSkipsRunAndKeepsHeartbeat(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        $heartbeatPath = CronProvisioner::heartbeatPath('minute', $this->runDir);
        file_put_contents($heartbeatPath, '{"old":true}');

        $lock = fopen(CronProvisioner::lockPath('minute', $this->runDir), 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute']);

        flock($lock, LOCK_UN);
        fclose($lock);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Previous run still active', $tester->getDisplay());
        $this->assertSame([], $cmd->callLog);
        $this->assertSame('{"old":true}', file_get_contents($heartbeatPath));
    }

    public function testSkipsDirsWithoutConfigMainJson(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        mkdir($this->dsDir . '/lost+found', 0755, true);

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertSame([['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-outbox-run']], $cmd->callLog);
        $this->assertSame(1, $this->readHeartbeat('minute')['dsCount']);
    }

    public function testMissingDataSourcesDirIsInfraFailure(): void
    {
        $cmd = new TestableCronCommand($this->tmpDir . '/nonexistent', $this->runDir, $this->logPath);
        $tester = new CommandTester($cmd);

        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Data sources directory not found', $tester->getDisplay());
    }

    public function testHeartbeatShape(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        [, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'five-minutes']);

        $hb = $this->readHeartbeat('five-minutes');
        $this->assertSame('five-minutes', $hb['slot']);
        $this->assertSame(CronProvisioner::TEMPLATE_VERSION, $hb['templateVersion']);
        $this->assertNotFalse(strtotime($hb['ts']));
        $this->assertIsString($hb['appVersion']);
        $this->assertIsInt($hb['durationMs']);
        $this->assertSame([], $hb['failures']);
    }

    public function testRealRunJobTimesOut(): void
    {
        $fixture = $this->tmpDir . '/slow-shpd-ds';
        file_put_contents($fixture, "#!/bin/sh\nsleep 5\n");
        chmod($fixture, 0755);

        $cmd = new TimeoutCronCommand($fixture);
        $started = microtime(true);
        $result = $cmd->runJobPublic($this->tmpDir, 'mail-outbox-run');
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['timedOut']);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testRealRunJobCapturesExitCodeAndOutput(): void
    {
        $fixture = $this->tmpDir . '/failing-shpd-ds';
        file_put_contents($fixture, "#!/bin/sh\necho 'some output'\nexit 3\n");
        chmod($fixture, 0755);

        $cmd = new TimeoutCronCommand($fixture);
        $result = $cmd->runJobPublic($this->tmpDir, 'alerts-run');

        $this->assertFalse($result['timedOut']);
        $this->assertSame(3, $result['exitCode']);
        $this->assertStringContainsString('some output', $result['output']);
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
