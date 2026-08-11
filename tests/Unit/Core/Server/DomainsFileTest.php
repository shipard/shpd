<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DomainsFile;

class DomainsFileTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd-domains-file-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            @chmod($this->tempDir, 0755);
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @chmod($f, 0644);
                is_dir($f) ? @rmdir($f) : @unlink($f);
            }
            @rmdir($this->tempDir);
        }
    }

    // ── effective paths ───────────────────────────────────────────────────

    public function testOverrideWinsOverServerConfig(): void
    {
        $this->assertSame('/x/domains.json', DomainsFile::effectiveDomainsFile('/x/domains.json', '/nonexistent'));
        $this->assertSame('/x/ds', DomainsFile::effectiveDataSourcesDir('/x/ds', '/nonexistent'));
    }

    public function testServerConfigOverridesAreRespected(): void
    {
        $configFile = $this->tempDir . '/server.json';
        file_put_contents($configFile, json_encode([
            'host' => 'localhost', 'port' => 3306, 'admin_user' => 'root',
            'admin_password' => 'x', 'mode' => 'production',
            'domainsFile' => '/opt/shipard/domains.json',
            'dataSources' => '/srv/shipard/ds',
        ]));

        $this->assertSame('/opt/shipard/domains.json', DomainsFile::effectiveDomainsFile(null, $configFile));
        $this->assertSame('/srv/shipard/ds', DomainsFile::effectiveDataSourcesDir(null, $configFile));
    }

    public function testMissingServerConfigFallsBackToDefaults(): void
    {
        $this->assertSame(
            DomainsFile::DEFAULT_DOMAINS_FILE,
            DomainsFile::effectiveDomainsFile(null, $this->tempDir . '/nonexistent.json'),
        );
        $this->assertSame(
            DomainsFile::DEFAULT_DATA_SOURCES_DIR,
            DomainsFile::effectiveDataSourcesDir(null, $this->tempDir . '/nonexistent.json'),
        );
    }

    public function testServerConfigWithoutOverridesUsesDefaults(): void
    {
        $configFile = $this->tempDir . '/server.json';
        file_put_contents($configFile, json_encode([
            'host' => 'localhost', 'port' => 3306, 'admin_user' => 'root',
            'admin_password' => 'x', 'mode' => 'production',
        ]));

        $this->assertSame(DomainsFile::DEFAULT_DOMAINS_FILE, DomainsFile::effectiveDomainsFile(null, $configFile));
        $this->assertSame(DomainsFile::DEFAULT_DATA_SOURCES_DIR, DomainsFile::effectiveDataSourcesDir(null, $configFile));
    }

    // ── load / save ───────────────────────────────────────────────────────

    public function testLoadMissingFileReturnsEmptyMap(): void
    {
        $this->assertSame([], DomainsFile::load($this->tempDir . '/nonexistent.json'));
    }

    public function testLoadInvalidJsonThrows(): void
    {
        $path = $this->tempDir . '/domains.json';
        file_put_contents($path, '{broken');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');
        DomainsFile::load($path);
    }

    public function testLoadUnreadableFileThrows(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root reads anything');
        }
        $path = $this->tempDir . '/domains.json';
        file_put_contents($path, '{}');
        chmod($path, 0000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot read/');
        DomainsFile::load($path);
    }

    public function testSaveRoundTrip(): void
    {
        $path = $this->tempDir . '/domains.json';
        DomainsFile::save($path, ['a.example.com' => 'aaaa-bbbb-cccc-dddd']);

        $this->assertSame(['a.example.com' => 'aaaa-bbbb-cccc-dddd'], DomainsFile::load($path));
        $this->assertFileDoesNotExist($path . '.tmp');
    }

    public function testSaveIntoUnwritableDirThrowsWithUserAndHint(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root writes anywhere');
        }
        chmod($this->tempDir, 0500);

        try {
            DomainsFile::save($this->tempDir . '/domains.json', ['a' => 'b']);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to write', $e->getMessage());
            $this->assertStringContainsString('running as', $e->getMessage());
            $this->assertStringContainsString("domainsFile", $e->getMessage());
        }
    }

    public function testSaveCreatesMissingDirectory(): void
    {
        $path = $this->tempDir . '/sub/domains.json';
        DomainsFile::save($path, []);

        $this->assertFileExists($path);
        @unlink($path);
        @rmdir($this->tempDir . '/sub');
    }
}
