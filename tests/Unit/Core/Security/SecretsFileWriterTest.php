<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Security\SecretsFileWriter;

class SecretsFileWriterTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd-secrets-writer-test-' . uniqid();
        mkdir($this->dsDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $secretsDir = $this->dsDir . '/secrets';
        if (is_dir($secretsDir)) {
            foreach (scandir($secretsDir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($secretsDir . '/' . $entry);
                }
            }
            @rmdir($secretsDir);
        }
        @rmdir($this->dsDir);
    }

    public function testWriteCreatesFileWithSecurePermissions(): void
    {
        $warnings = SecretsFileWriter::write($this->dsDir, 'test.key', 'secret-content');

        $keyFile = $this->dsDir . '/secrets/test.key';
        $this->assertFileExists($keyFile);
        $this->assertSame('secret-content', file_get_contents($keyFile));
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame(0700, fileperms($this->dsDir . '/secrets') & 0777);
        // Proces = vlastník DS adresáře → žádný nesoulad.
        $this->assertSame([], $warnings);
    }

    public function testWriteOverwritesExistingFile(): void
    {
        SecretsFileWriter::write($this->dsDir, 'test.key', 'old');
        SecretsFileWriter::write($this->dsDir, 'test.key', 'new');

        $this->assertSame('new', file_get_contents($this->dsDir . '/secrets/test.key'));
    }

    public function testWriteLeavesNoTmpFile(): void
    {
        SecretsFileWriter::write($this->dsDir, 'test.key', 'x');

        $this->assertFileDoesNotExist($this->dsDir . '/secrets/test.key.tmp');
    }

    public function testAlignOwnershipWarnsOnMismatchWhenNotRoot(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root would chown instead of warning');
        }

        SecretsFileWriter::write($this->dsDir, 'test.key', 'x');
        $keyFile = $this->dsDir . '/secrets/test.key';

        // '/' patří rootovi, soubor testovacímu uživateli → nesoulad.
        $warnings = SecretsFileWriter::alignOwnership('/', [$keyFile]);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($keyFile, $warnings[0]);
        $this->assertStringContainsString('sudo chown root:root ' . $keyFile, $warnings[0]);
    }

    public function testAlignOwnershipSilentWhenOwnersMatch(): void
    {
        SecretsFileWriter::write($this->dsDir, 'test.key', 'x');

        $warnings = SecretsFileWriter::alignOwnership($this->dsDir, [$this->dsDir . '/secrets/test.key']);

        $this->assertSame([], $warnings);
    }

    public function testAlignOwnershipWarnsWhenDsPathUnreadable(): void
    {
        $warnings = SecretsFileWriter::alignOwnership($this->dsDir . '/nonexistent', []);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Cannot determine owner', $warnings[0]);
    }
}
