<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DsUpgradeAllCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsUpgradeAllCommand extends DsUpgradeAllCommand
{
    /** @var array<string, array{success: bool, exitCode: int}> */
    private array $upgradeResults = [];

    /** @var list<string> */
    public array $callLog = [];

    /** @var list<array{id: string, verbosity: string}> */
    public array $verbosityLog = [];

    public function __construct(private readonly string $dataSourcesDir)
    {
        parent::__construct();
    }

    /**
     * @param array<string, array{success: bool, exitCode: int}> $results
     */
    public function setUpgradeResults(array $results): void
    {
        $this->upgradeResults = $results;
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function runDsUpgrade(string $dsDir, OutputInterface $output): array
    {
        $id = basename($dsDir);
        $this->callLog[] = $id;

        $verbosityFlag = match (true) {
            $output->isDebug() => '-vvv',
            $output->isVeryVerbose() => '-vv',
            $output->isVerbose() => '-v',
            default => '',
        };
        $this->verbosityLog[] = ['id' => $id, 'verbosity' => $verbosityFlag];

        return $this->upgradeResults[$id] ?? ['success' => true, 'exitCode' => 0];
    }
}

class DsUpgradeAllCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    private function createDs(string $id): string
    {
        $dsDir = $this->tmpDir . '/' . $id;
        mkdir($dsDir . '/config', 0755, true);
        file_put_contents($dsDir . '/config/main.json', '{}');
        return $dsDir;
    }

    private function makeTester(): array
    {
        $cmd = new TestableDsUpgradeAllCommand($this->tmpDir);
        $tester = new CommandTester($cmd);
        return [$cmd, $tester];
    }

    public function testEmptyDirectoryReportsNoneAndSucceeds(): void
    {
        [, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No data sources found', $tester->getDisplay());
    }

    public function testAllSucceedSummariesAndCallsAll(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');
        $this->createDs('cccc-cccc-cccc-cccc');

        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('3 upgraded, 0 failed', $tester->getDisplay());
        $this->assertCount(3, $cmd->callLog);
    }

    public function testOneFailsContinuesByDefault(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');
        $this->createDs('cccc-cccc-cccc-cccc');

        [$cmd, $tester] = $this->makeTester();
        $cmd->setUpgradeResults([
            'bbbb-bbbb-bbbb-bbbb' => ['success' => false, 'exitCode' => 1],
        ]);

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('2 upgraded, 1 failed', $output);
        $this->assertStringContainsString('Failed: bbbb-bbbb-bbbb-bbbb', $output);
        $this->assertCount(3, $cmd->callLog);
    }

    public function testStopOnErrorBreaksAfterFailure(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');
        $this->createDs('cccc-cccc-cccc-cccc');

        [$cmd, $tester] = $this->makeTester();
        $cmd->setUpgradeResults([
            'bbbb-bbbb-bbbb-bbbb' => ['success' => false, 'exitCode' => 1],
        ]);

        $exit = $tester->execute(['--stop-on-error' => true]);

        $this->assertSame(1, $exit);
        $this->assertCount(2, $cmd->callLog);
        $this->assertSame(['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb'], $cmd->callLog);
    }

    public function testDsFilterRunsOnlyOne(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');
        $this->createDs('cccc-cccc-cccc-cccc');

        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute(['--ds' => 'bbbb-bbbb-bbbb-bbbb']);

        $this->assertSame(0, $exit);
        $this->assertSame(['bbbb-bbbb-bbbb-bbbb'], $cmd->callLog);
    }

    public function testDsFilterNonexistentReturnsSuccessWithMessage(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute(['--ds' => 'zzzz-zzzz-zzzz-zzzz']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No data source found with ID:', $tester->getDisplay());
        $this->assertSame([], $cmd->callLog);
    }

    public function testDryRunDoesNotInvokeUpgrade(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');

        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--dry-run', $tester->getDisplay());
        $this->assertSame([], $cmd->callLog);
    }

    public function testDefaultVerbosityDoesNotPropagateFlag(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertSame(
            [['id' => 'aaaa-aaaa-aaaa-aaaa', 'verbosity' => '']],
            $cmd->verbosityLog,
        );
    }

    public function testVerboseFlagPropagatesToSubprocess(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertSame(0, $exit);
        $this->assertSame(
            [['id' => 'aaaa-aaaa-aaaa-aaaa', 'verbosity' => '-v']],
            $cmd->verbosityLog,
        );
    }

    public function testSkipsDirsWithoutConfigMainJson(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        // Bare directory with no config/main.json — should be skipped.
        mkdir($this->tmpDir . '/lost+found', 0755, true);

        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertSame(['aaaa-aaaa-aaaa-aaaa'], $cmd->callLog);
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
