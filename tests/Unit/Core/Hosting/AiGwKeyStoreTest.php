<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Hosting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Hosting\AiGwKeyStore;
use Shipard\Core\Hosting\Exception\AiGwKeyInsecureException;
use Shipard\Core\Hosting\Exception\AiGwKeyMissingException;

class AiGwKeyStoreTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_aigwkey_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        AiGwKeyStore::resetCache();
    }

    protected function tearDown(): void
    {
        AiGwKeyStore::resetCache();
        $this->rmdir($this->tempDir);
    }

    private function config(): DataSourceConfig
    {
        $config = $this->createMock(DataSourceConfig::class);
        $config->method('getDataSourceDir')->willReturn($this->tempDir);
        return $config;
    }

    public function testWriteAndReadRoundTrip(): void
    {
        AiGwKeyStore::write($this->tempDir, "sk-ant-test-key\n");

        $keyFile = AiGwKeyStore::keyFilePath($this->tempDir);
        $this->assertFileExists($keyFile);
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame(0700, fileperms(dirname($keyFile)) & 0777);

        $this->assertTrue(AiGwKeyStore::exists($this->tempDir));
        $this->assertSame('sk-ant-test-key', AiGwKeyStore::read($this->config()));
    }

    public function testWriteOverwritesExistingKey(): void
    {
        AiGwKeyStore::write($this->tempDir, 'old-key');
        AiGwKeyStore::write($this->tempDir, 'new-key');

        $this->assertSame('new-key', AiGwKeyStore::read($this->config()));
    }

    public function testReadMissingKeyThrows(): void
    {
        $this->assertFalse(AiGwKeyStore::exists($this->tempDir));

        $this->expectException(AiGwKeyMissingException::class);
        $this->expectExceptionMessageMatches('/hosting-ai-gw-init/');
        AiGwKeyStore::read($this->config());
    }

    public function testReadInsecurePermissionsThrows(): void
    {
        AiGwKeyStore::write($this->tempDir, 'sk-ant-test-key');
        chmod(AiGwKeyStore::keyFilePath($this->tempDir), 0644);

        $this->expectException(AiGwKeyInsecureException::class);
        $this->expectExceptionMessageMatches('/0600/');
        AiGwKeyStore::read($this->config());
    }

    public function testReadEmptyFileThrows(): void
    {
        AiGwKeyStore::write($this->tempDir, 'x');
        file_put_contents(AiGwKeyStore::keyFilePath($this->tempDir), "  \n");

        $this->expectException(AiGwKeyMissingException::class);
        $this->expectExceptionMessageMatches('/empty/');
        AiGwKeyStore::read($this->config());
    }

    public function testWriteEmptyKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AiGwKeyStore::write($this->tempDir, "  \n");
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
