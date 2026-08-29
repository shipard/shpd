<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Process;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Process\DetachedProcess;

class DetachedProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v setsid 2>/dev/null')) === '') {
            $this->markTestSkipped('setsid (util-linux) is not available');
        }
        $this->dir = sys_get_temp_dir() . '/shpd_detached_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testSpawnReturnsImmediatelyAndChildRunsToCompletion(): void
    {
        $marker = $this->dir . '/marker';
        $log = $this->dir . '/out.log';

        $started = microtime(true);
        $ok = DetachedProcess::spawn(
            ['sh', '-c', 'sleep 0.3; echo child-done; echo "$PWD" > ' . escapeshellarg($marker)],
            $this->dir,
            $log,
        );
        $elapsed = microtime(true) - $started;

        $this->assertTrue($ok);
        $this->assertLessThan(0.25, $elapsed, 'spawn must not wait for the child');

        $deadline = microtime(true) + 3;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($marker);
        $this->assertSame(realpath($this->dir), realpath(trim((string) file_get_contents($marker))));
        $this->assertStringContainsString('child-done', (string) file_get_contents($log));
    }

    public function testEmptyArgvIsRejected(): void
    {
        $this->assertFalse(DetachedProcess::spawn([]));
    }
}
