<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsUpgradeCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsUpgradeCommand extends DsUpgradeCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $modulesPath,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function getModulesBasePath(): string
    {
        return $this->modulesPath;
    }
}

class DsUpgradeCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;
    private string $modulesPath;
    private string $dsDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_upgrade_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->modulesPath = $this->tempDir . '/modules';
        $this->dsDir = $this->tempDir . '/ds';

        $this->createFixtures();

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit']);
        $this->dsConfig->method('getName')->willReturn('Test DS');
        $this->dsConfig->method('getId')->willReturn('test-0001-test-0001');
        $this->dsConfig->method('getDatabaseName')->willReturn('test_db');
        $this->dsConfig->method('getDatabaseUser')->willReturn('shpd_test0001');
        $this->dsConfig->method('getDatabasePassword')->willReturn('secret');
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);

        $this->dsConnection = $this->createMock(DataSourceConnection::class);

        DsSecretCipher::resetCache();
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rmdirRecursive($this->tempDir);
    }

    private function createFixtures(): void
    {
        // Create module structure
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir . '/tables', 0755, true);
        mkdir($this->dsDir . '/config/configuration', 0755, true);

        // module.jsonc
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'test.unit',
            'name'         => 'Test Unit Module',
            'dependencies' => [],
            'tables'       => ['test_unit_items'],
            'extensions'   => [],
            'config'       => [],
        ]));

        // table definition
        file_put_contents($moduleDir . '/tables/test_unit_items.jsonc', json_encode([
            'tableId' => 1,
            'name'    => 'test_unit_items',
            'columns' => [
                [
                    'id'            => 'id',
                    'name'          => 'ID',
                    'type'          => 'int',
                    'primaryKey'    => true,
                    'autoIncrement' => true,
                    'nullable'      => false,
                ],
                [
                    'id'       => 'label',
                    'name'     => 'Label',
                    'type'     => 'varchar',
                    'length'   => 100,
                    'nullable' => false,
                ],
            ],
            'indexes' => [
                [
                    'id'      => 'idx_label',
                    'type'    => 'index',
                    'columns' => [['column' => 'label', 'order' => 'ASC']],
                ],
            ],
        ]));
    }

    private function createCommandTester(): CommandTester
    {
        $command = new TestableDsUpgradeCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->modulesPath,
            $this->dsDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testUpgradeCreatesNewTable(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);

        $executedSQLs = [];
        $this->dsConnection->method('executeSQL')
            ->willReturnCallback(function (string $sql) use (&$executedSQLs): void {
                $executedSQLs[] = $sql;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('[CREATE]', $tester->getDisplay());

        $hasCREATETABLE = false;
        foreach ($executedSQLs as $sql) {
            if (str_contains(strtoupper($sql), 'CREATE TABLE')) {
                $hasCREATETABLE = true;
                break;
            }
        }
        $this->assertTrue($hasCREATETABLE, 'Expected CREATE TABLE SQL to be executed');
    }

    public function testUpgradeAltersExistingTable(): void
    {
        // Simulate table exists with only 'id' column — 'label' is missing
        $this->dsConnection->method('getTableColumns')->willReturn([
            'id' => 'int(11)',
        ]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);

        $executedSQLs = [];
        $this->dsConnection->method('executeSQL')
            ->willReturnCallback(function (string $sql) use (&$executedSQLs): void {
                $executedSQLs[] = $sql;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('[ALTER]', $tester->getDisplay());
    }

    public function testUpgradeNoChanges(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([
            'id'    => 'int(11)',
            'label' => 'varchar(100)',
        ]);
        $this->dsConnection->method('getTableIndexes')->willReturn(['idx_label']);
        $this->dsConnection->expects($this->never())->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('[OK]', $tester->getDisplay());
    }

    public function testUpgradeValidationErrorAborts(): void
    {
        // Create a second module with duplicate tableId=1 to trigger validation error
        $moduleDir2 = $this->modulesPath . '/test/extra';
        mkdir($moduleDir2 . '/tables', 0755, true);

        file_put_contents($moduleDir2 . '/module.jsonc', json_encode([
            'id'           => 'test.extra',
            'name'         => 'Test Extra Module',
            'dependencies' => [],
            'tables'       => ['test_extra_things'],
            'extensions'   => [],
            'config'       => [],
        ]));

        file_put_contents($moduleDir2 . '/tables/test_extra_things.jsonc', json_encode([
            'tableId' => 1, // duplicate!
            'name'    => 'test_extra_things',
            'columns' => [
                [
                    'id'            => 'id',
                    'name'          => 'ID',
                    'type'          => 'int',
                    'primaryKey'    => true,
                    'autoIncrement' => true,
                    'nullable'      => false,
                ],
            ],
            'indexes' => [],
        ]));

        // Both modules activated
        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit', 'test.extra']);
        $this->dsConfig->method('getName')->willReturn('Test DS');
        $this->dsConfig->method('getId')->willReturn('test-0001-test-0001');
        $this->dsConfig->method('getDatabaseName')->willReturn('test_db');
        $this->dsConfig->method('getDatabaseUser')->willReturn('shpd_test0001');
        $this->dsConfig->method('getDatabasePassword')->willReturn('secret');
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Duplicate tableId', $tester->getDisplay());
    }

    public function testUpgradeSummaryLine(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression(
            '/Upgrade complete\. \d+ tables? created, \d+ tables? altered, \d+ tables? unchanged\./',
            $display
        );
    }

    public function testUpgradeGeneratesMissingSecretsKey(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $keyFile = $this->dsDir . '/secrets/secrets.key';
        $this->assertFileDoesNotExist($keyFile);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($keyFile);
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame(0700, fileperms($this->dsDir . '/secrets') & 0777);
        $this->assertStringContainsString('Created secrets/secrets.key', $tester->getDisplay());
    }

    public function testUpgradeLeavesExistingSecretsKeyAlone(): void
    {
        // Pre-create a key
        DsSecretCipher::generateKey($this->dsDir);
        $keyFile = $this->dsDir . '/secrets/secrets.key';
        $original = file_get_contents($keyFile);
        $originalMtime = filemtime($keyFile);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        clearstatcache();
        sleep(1); // ensure mtime would change if rewritten

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame($original, file_get_contents($keyFile));
        $this->assertSame($originalMtime, filemtime($keyFile));
        $this->assertStringNotContainsString('Created secrets/secrets.key', $tester->getDisplay());
    }

    public function testUpgradeLogsInfoForEncryptedColumnAdd(): void
    {
        // Override the fixture: drop the existing module and create one with an encrypted_text column
        $this->rmdirRecursive($this->modulesPath);
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir . '/tables', 0755, true);

        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'test.unit',
            'name'         => 'Test Unit Module',
            'dependencies' => [],
            'tables'       => ['test_unit_secret'],
            'extensions'   => [],
            'config'       => [],
        ]));

        file_put_contents($moduleDir . '/tables/test_unit_secret.jsonc', json_encode([
            'tableId' => 1,
            'name'    => 'test_unit_secret',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true, 'nullable' => false],
                ['id' => 'api_key', 'name' => 'API key', 'type' => 'encrypted_text', 'nullable' => true],
            ],
            'indexes' => [],
        ]));

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString("[INFO] Adding encrypted_text column 'test_unit_secret.api_key'", $display);
        $this->assertStringContainsString('Application layer must use DsSecretCipher', $display);
    }

    public function testUpgradeWritesCompiledConfig(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertFileExists($this->dsDir . '/config/configuration/compiled.cs.json');
        $this->assertFileExists($this->dsDir . '/config/configuration/compiled.en.json');
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
