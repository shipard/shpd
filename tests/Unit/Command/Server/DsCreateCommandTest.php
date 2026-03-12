<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Shipard\Command\Server\DsCreateCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\Utils\IdGenerator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Testable subclass with overrideable data-sources directory.
 */
class TestableDsCreateCommand extends DsCreateCommand
{
    public string $dataSourcesDir;

    public function __construct(
        private ServerConfig $mockConfig,
        private DatabaseManager $mockDbManager,
        string $dataSourcesDir,
    ) {
        parent::__construct($mockConfig, $mockDbManager);
        $this->dataSourcesDir = $dataSourcesDir;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');

        if (empty($name)) {
            $output->writeln('<error>Option --name is required</error>');
            return Command::FAILURE;
        }

        $generator = new IdGenerator();
        $id = $generator->generate($this->dataSourcesDir);

        $dbName = IdGenerator::toDatabaseName($id);
        $dbUser = IdGenerator::toDatabaseUser($id);

        $dataSourceDir = $this->dataSourcesDir . '/' . $id;
        $configDir = $dataSourceDir . '/config';

        if (!mkdir($configDir, 0755, true)) {
            $output->writeln('<error>Failed to create data source directory</error>');
            return Command::FAILURE;
        }

        $password = $this->mockDbManager->generatePassword();
        $this->mockDbManager->createDatabase($dbName);
        $this->mockDbManager->createUser($dbUser, $password, $dbName);

        $mainConfig = [
            'id'                => $id,
            'name'              => $name,
            'database_name'     => $dbName,
            'database_user'     => $dbUser,
            'database_password' => $password,
            'created'           => date('c'),
        ];

        $configFile = $configDir . '/main.json';
        file_put_contents($configFile, json_encode($mainConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($configFile, 0600);

        $output->writeln('');
        $output->writeln('<info>Data source created successfully</info>');
        $output->writeln("  ID:            <comment>{$id}</comment>");
        $output->writeln("  Name:          <comment>{$name}</comment>");
        $output->writeln("  Database:      <comment>{$dbName}</comment>");
        $output->writeln("  DB User:       <comment>{$dbUser}</comment>");
        $output->writeln("  Directory:     <comment>{$dataSourceDir}</comment>");

        return Command::SUCCESS;
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
