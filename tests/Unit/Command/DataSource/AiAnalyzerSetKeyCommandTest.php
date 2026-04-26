<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiAnalyzerSetKeyCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableAiAnalyzerSetKeyCommand extends AiAnalyzerSetKeyCommand
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

class AiAnalyzerSetKeyCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tempDir = sys_get_temp_dir() . '/shpd_ai_setkey_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'AISetKey Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tempDir);

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getId')->willReturn('test-test-test-test');
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->tempDir);

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tempDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableAiAnalyzerSetKeyCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testFailsWithoutApiKey(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--api-key is required', $tester->getDisplay());
    }

    public function testFailsWhenBackendNotFound(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--api-key' => 'sk-ant-test']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("backend 'default' not found", $tester->getDisplay());
        $this->assertStringContainsString('ai-analyzer-bootstrap', $tester->getDisplay());
    }

    public function testEncryptsKeyAndActivatesBackend(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);

        $captured = null;
        $this->dsConnection
            ->expects($this->once())
            ->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data, string $where, mixed ...$params) use (&$captured): void {
                $captured = ['table' => $table, 'data' => $data, 'where' => $where, 'params' => $params];
            });

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--backend' => 'default',
            '--api-key' => 'sk-ant-plaintext-secret-✓',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNotNull($captured);
        $this->assertSame('core_mail_ai_backends', $captured['table']);
        $this->assertSame([17], $captured['params']);

        // Klíčové bezpečnostní invarianty:
        $this->assertArrayHasKey('api_key', $captured['data']);
        $this->assertNotSame('sk-ant-plaintext-secret-✓', $captured['data']['api_key']);
        $this->assertStringStartsWith('v1:', $captured['data']['api_key']);
        $this->assertSame(1, $captured['data']['is_active']);

        // Plaintext nesmí prosáknout do výstupu (CLI ho nemá logovat)
        $this->assertStringNotContainsString('sk-ant-plaintext-secret', $tester->getDisplay());
    }

    public function testFailsWhenSecretsKeyMissing(): void
    {
        // Smažeme secrets.key — DsSecretCipher::forConfig hodí výjimku
        @chmod($this->tempDir . '/secrets/secrets.key', 0600);
        unlink($this->tempDir . '/secrets/secrets.key');
        DsSecretCipher::resetCache();

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--backend' => 'default',
            '--api-key' => 'sk-ant-test',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Secrets key error', $tester->getDisplay());
        $this->assertStringContainsString('ds-secrets-health', $tester->getDisplay());
    }
}
