<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;

class ServerConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    private function createConfig(array $data): string
    {
        $path = $this->tempDir . '/server.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    public function testLoadValidConfig(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('127.0.0.1', $config->getHost());
        $this->assertSame(3306, $config->getPort());
        $this->assertSame('root', $config->getAdminUser());
        $this->assertSame('secret', $config->getAdminPassword());
        $this->assertSame('production', $config->getMode());
        $this->assertSame('/etc/shipard/domains.json', $config->getDomainsFile());
    }

    public function testGetDomainsFileCustomPath(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'domainsFile'    => '/custom/path/domains.json',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('/custom/path/domains.json', $config->getDomainsFile());
    }

    public function testLoadMissingFileThrowsException(): void
    {
        $config = new ServerConfig($this->tempDir . '/nonexistent.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $config->load();
    }

    public function testLoadInvalidJsonThrowsException(): void
    {
        $path = $this->tempDir . '/server.json';
        file_put_contents($path, '{invalid json}');

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid json/i');

        $config->load();
    }

    public function testLoadMissingRequiredFieldThrowsException(): void
    {
        $path = $this->createConfig([
            'host' => '127.0.0.1',
            // missing: port, admin_user, admin_password, mode
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required/i');

        $config->load();
    }

    public function testGetLogFileDefault(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('/opt/shipard/log/shipard.log', $config->getLogFile());
        $this->assertSame('debug', $config->getLogLevel());
    }

    public function testGetLogFileAndLevelCustom(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'logFile'        => '/var/log/shipard-custom.log',
            'logLevel'       => 'warn',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('/var/log/shipard-custom.log', $config->getLogFile());
        $this->assertSame('warn', $config->getLogLevel());
    }

    public function testGetExtraModulesPathDefaultsToEmpty(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame([], $config->getExtraModulesPath());
    }

    public function testGetExtraModulesPathReturnsList(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['/opt/customer-a/modules', '/opt/customer-b/modules'],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame(
            ['/opt/customer-a/modules', '/opt/customer-b/modules'],
            $config->getExtraModulesPath(),
        );
    }

    public function testExtraModulesPathRejectsNonArray(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => '/just/a/string',
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath.*array/i');

        $config->load();
    }

    public function testExtraModulesPathRejectsNonStringEntry(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['/ok', 123],
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath\[1\]/');

        $config->load();
    }

    public function testExtraModulesPathRejectsEmptyStringEntry(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['/ok', ''],
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath\[1\]/');

        $config->load();
    }

    public function testExtraModulesPathRejectsAssociativeArray(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['key' => '/path'],
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath.*array/i');

        $config->load();
    }

    public function testLoadMissingEachRequiredField(): void
    {
        $required = ['host', 'port', 'admin_user', 'admin_password', 'mode'];
        $fullData = [
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ];

        foreach ($required as $field) {
            $data = $fullData;
            unset($data[$field]);

            $path = $this->createConfig($data);
            $config = new ServerConfig($path);

            try {
                $config->load();
                $this->fail("Expected RuntimeException for missing field: {$field}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsStringIgnoringCase('missing required', $e->getMessage());
            }
        }
    }
}
