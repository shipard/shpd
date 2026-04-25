<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsSecretsHealthCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsSecretsHealthCommand extends DsSecretsHealthCommand
{
    public function __construct(
        DataSourceConfig $cfg,
        ?DataSourceConnection $conn,
        private readonly string $modulesPath,
        private readonly string $dsDir,
    ) {
        parent::__construct($cfg, $conn);
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

class DsSecretsHealthCommandTest extends TestCase
{
    private string $tempDir;
    private string $modulesPath;
    private string $dsDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd-secrets-health-' . bin2hex(random_bytes(8));
        $this->modulesPath = $this->tempDir . '/modules';
        $this->dsDir = $this->tempDir . '/ds';
        mkdir($this->modulesPath, 0755, true);
        mkdir($this->dsDir . '/config', 0755, true);

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit']);
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);

        $this->dsConnection = $this->createMock(DataSourceConnection::class);

        DsSecretCipher::resetCache();
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rmdirRecursive($this->tempDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeModuleWithoutEncryptedColumns(): void
    {
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir . '/tables', 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id' => 'test.unit',
            'name' => 'Test',
            'dependencies' => [],
            'tables' => ['test_unit_plain'],
            'extensions' => [],
            'config' => [],
        ]));
        file_put_contents($moduleDir . '/tables/test_unit_plain.jsonc', json_encode([
            'tableId' => 1,
            'name' => 'test_unit_plain',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true, 'nullable' => false],
                ['id' => 'label', 'name' => 'Label', 'type' => 'varchar', 'length' => 100, 'nullable' => false],
            ],
        ]));
    }

    private function writeModuleWithEncryptedColumn(): void
    {
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir . '/tables', 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id' => 'test.unit',
            'name' => 'Test',
            'dependencies' => [],
            'tables' => ['test_unit_secret'],
            'extensions' => [],
            'config' => [],
        ]));
        file_put_contents($moduleDir . '/tables/test_unit_secret.jsonc', json_encode([
            'tableId' => 2,
            'name' => 'test_unit_secret',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true, 'nullable' => false],
                ['id' => 'api_key', 'name' => 'API key', 'type' => 'encrypted_text', 'nullable' => true],
            ],
        ]));
    }

    private function makeTester(): CommandTester
    {
        $command = new TestableDsSecretsHealthCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->modulesPath,
            $this->dsDir,
        );
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    public function testHappyPathNoEncryptedColumns(): void
    {
        $this->writeModuleWithoutEncryptedColumns();
        DsSecretCipher::generateKey($this->dsDir);

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('secrets.key present', $display);
        $this->assertStringContainsString('secrets.key permissions 0600', $display);
        $this->assertStringContainsString('secrets/ directory permissions 0700', $display);
        $this->assertStringContainsString('All checks passed', $display);
    }

    public function testHappyPathWithEncryptedColumns(): void
    {
        $this->writeModuleWithEncryptedColumn();
        DsSecretCipher::generateKey($this->dsDir);

        $cipher = DsSecretCipher::forConfig($this->dsConfig);
        $rows = [
            ['id' => 1, 'val' => $cipher->encrypt('secret-1')],
            ['id' => 2, 'val' => $cipher->encrypt('secret-2')],
            ['id' => 3, 'val' => $cipher->encrypt('secret-3')],
        ];
        $this->dsConnection->method('fetchAll')->willReturn($rows);

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('test_unit_secret.api_key — 3 rows, all decryptable', $display);
        $this->assertStringContainsString('All checks passed', $display);
    }

    public function testMissingKeyExitsWith2(): void
    {
        $this->writeModuleWithoutEncryptedColumns();
        // intentionally do NOT generate the key

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(2, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('secrets.key missing', $display);
    }

    public function testWrongFilePermissionsExitsWith1(): void
    {
        $this->writeModuleWithoutEncryptedColumns();
        DsSecretCipher::generateKey($this->dsDir);
        $keyFile = $this->dsDir . '/secrets/secrets.key';
        chmod($keyFile, 0644);
        DsSecretCipher::resetCache();

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('secrets.key permissions are 0644', $display);
        $this->assertStringContainsString('Fix: chmod 0600', $display);
    }

    public function testWrongDirPermissionsExitsWith1(): void
    {
        $this->writeModuleWithoutEncryptedColumns();
        DsSecretCipher::generateKey($this->dsDir);
        chmod($this->dsDir . '/secrets', 0755);

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('secrets/ directory permissions are 0755', $display);
        $this->assertStringContainsString('Fix: chmod 0700', $display);
    }

    public function testCorruptedCiphertextExitsWith2(): void
    {
        $this->writeModuleWithEncryptedColumn();
        DsSecretCipher::generateKey($this->dsDir);

        $cipher = DsSecretCipher::forConfig($this->dsConfig);
        $rows = [
            ['id' => 1, 'val' => $cipher->encrypt('ok')],
            ['id' => 42, 'val' => 'v1:aaaa:bbbb:cccc'], // malformed
            ['id' => 7, 'val' => $cipher->encrypt('also-ok')],
        ];
        $this->dsConnection->method('fetchAll')->willReturn($rows);

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(2, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 of 3 rows failed decryption', $display);
        $this->assertStringContainsString('row id=42', $display);
    }
}
