<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiAnalyzerSetupCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableAiAnalyzerSetupCommand extends AiAnalyzerSetupCommand
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

/**
 * Kontrakt `--json` módu (hosting-10 D3) — stdout je jediný JSON objekt
 * {"api_key", "user_id"}, lidské hlášky jdou na stderr; bez --json se
 * chování nemění. Zrcadlo testů MailRouterSetupCommandTest.
 */
class AiAnalyzerSetupCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_aisetup_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getId')->willReturn('abcd-efgh-ijkl-mnop');

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableAiAnalyzerSetupCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testJsonOutputIsSingleJsonObject(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            null,          // user lookup → miss (created line se v json módu potlačí)
            null,          // active key lookup → none
        );
        $this->dsConnection->method('insertRow')->willReturnOnConsecutiveCalls(2, 99);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        // Stdout = jediný JSON objekt, žádné dekorace.
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded);
        $this->assertSame(['api_key', 'user_id'], array_keys($decoded));
        $this->assertMatchesRegularExpression('/^shpd_ak_[0-9a-f]{32}$/', $decoded['api_key']);
        $this->assertSame(2, $decoded['user_id']);
    }

    public function testJsonWithForceRotatesSilently(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 2],
            ['id' => 50],  // existing active key
        );
        $this->dsConnection->expects($this->once())->method('execute');
        $this->dsConnection->method('insertRow')->willReturn(99);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--force' => true, '--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded);
        $this->assertMatchesRegularExpression('/^shpd_ak_[0-9a-f]{32}$/', $decoded['api_key']);
    }

    public function testJsonRefusesToRotateWithoutForce(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 2],
            ['id' => 50],  // existing active key
        );
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        // CommandTester slévá stderr do displaye — chyba tam je, ale stdout
        // JSON objekt ne.
        $this->assertStringContainsString('already exists', $tester->getDisplay());
        $this->assertStringNotContainsString('api_key', $tester->getDisplay());
    }

    public function testWithoutJsonOutputIsUnchanged(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(['id' => 2], null);
        $this->dsConnection->method('insertRow')->willReturn(99);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('API Key created for data source abcd-efgh-ijkl-mnop', $output);
        $this->assertStringContainsString('IMPORTANT: This is the only time', $output);
        $this->assertMatchesRegularExpression('/shpd_ak_[0-9a-f]{32}/', $output);
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
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
