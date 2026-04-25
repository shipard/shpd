<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Shipard\Command\Server\DsCreateCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsCreateCommand extends DsCreateCommand
{
    public function __construct(
        ServerConfig $mockConfig,
        DatabaseManager $mockDbManager,
        private readonly string $dataSourcesDir,
    ) {
        parent::__construct($mockConfig, $mockDbManager);
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }
}

class DsCreateCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $serverConfig;
    private MockObject $databaseManager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_cmd_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->serverConfig = $this->createMock(ServerConfig::class);
        $this->serverConfig->method('load');

        $this->databaseManager = $this->createMock(DatabaseManager::class);
        $this->databaseManager->method('generatePassword')->willReturn('TestPassword123!@#$');
        $this->databaseManager->method('createDatabase');
        $this->databaseManager->method('createUser');
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function createCommandTester(): CommandTester
    {
        $command = new TestableDsCreateCommand(
            $this->serverConfig,
            $this->databaseManager,
            $this->tempDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testDsCreateRequiresName(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--name is required', $tester->getDisplay());
    }

    public function testDsCreateSucceeds(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['--name' => 'My Test DS']);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Data source created successfully', $output);
        $this->assertStringContainsString('My Test DS', $output);
    }

    public function testDsCreateWritesConfigFile(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--name' => 'Test']);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);

        $configFile = $dirs[0] . '/config/main.json';
        $this->assertFileExists($configFile);

        $config = json_decode(file_get_contents($configFile), true);
        $this->assertSame('Test', $config['name']);
        $this->assertArrayHasKey('id', $config);
        $this->assertArrayHasKey('database_name', $config);
        $this->assertArrayHasKey('database_user', $config);
        $this->assertArrayHasKey('database_password', $config);
        $this->assertArrayHasKey('created', $config);
    }

    public function testDsCreateIdFormat(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--name' => 'Test']);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $dirName = basename($dirs[0]);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', $dirName);
    }

    public function testDsCreateGeneratesSecretsKey(): void
    {
        DsSecretCipher::resetCache();
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Secrets Test']);
        $this->assertSame(0, $exitCode);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);
        $dsDir = $dirs[0];

        $secretsDir = $dsDir . '/secrets';
        $keyFile = $secretsDir . '/secrets.key';

        $this->assertDirectoryExists($secretsDir);
        $this->assertFileExists($keyFile);

        $this->assertSame(0700, fileperms($secretsDir) & 0777);
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame(DsSecretCipher::KEY_BYTES, filesize($keyFile));
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
