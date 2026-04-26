<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiAnalyzerBootstrapCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableAiAnalyzerBootstrapCommand extends AiAnalyzerBootstrapCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class AiAnalyzerBootstrapCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_ai_bootstrap_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getId')->willReturn('test-test-test-test');

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableAiAnalyzerBootstrapCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testCreatesAllOnFreshDs(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->method('insertRow')
            ->willReturnOnConsecutiveCalls(42, 17, 33);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString("Created system user '_ai_analyzer' (id=42)", $output);
        $this->assertStringContainsString('Created default AI backend (id=17)', $output);
        $this->assertStringContainsString('Created default AI profile (id=33)', $output);
        $this->assertStringContainsString('ai-analyzer-set-key', $output);
    }

    public function testIsIdempotent(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1], // user
            ['id' => 2], // backend
            ['id' => 3], // profile
        );
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString("'_ai_analyzer' already exists", $output);
        $this->assertStringContainsString('Default backend already exists', $output);
        $this->assertStringContainsString('Default profile already exists', $output);
    }
}
