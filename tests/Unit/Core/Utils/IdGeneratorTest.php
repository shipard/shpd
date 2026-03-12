<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Utils;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\IdGenerator;

class IdGeneratorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    public function testGenerateMatchesFormat(): void
    {
        $generator = new IdGenerator();
        $id = $generator->generate($this->tempDir);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', $id);
    }

    public function testGenerateHasCorrectLength(): void
    {
        $generator = new IdGenerator();
        $id = $generator->generate($this->tempDir);

        // 4 groups of 4 chars + 3 dashes = 19
        $this->assertSame(19, strlen($id));
    }

    public function testGenerateUsesCorrectCharset(): void
    {
        $generator = new IdGenerator();
        $id = $generator->generate($this->tempDir);

        $withoutDashes = str_replace('-', '', $id);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $withoutDashes);
    }

    public function testGenerateIsUnique(): void
    {
        $generator = new IdGenerator();
        $ids = [];

        for ($i = 0; $i < 100; $i++) {
            $id = $generator->generate($this->tempDir);
            $this->assertNotContains($id, $ids, "Duplicate ID generated: {$id}");
            $ids[] = $id;
            // Simulate existing data source dir so next call avoids collision
            mkdir($this->tempDir . '/' . $id, 0755);
        }
    }

    public function testGenerateSkipsExistingDirectories(): void
    {
        $generator = new IdGenerator();

        // Pre-create a directory with a known ID to simulate collision
        $firstId = $generator->generate($this->tempDir);
        mkdir($this->tempDir . '/' . $firstId, 0755);

        $secondId = $generator->generate($this->tempDir);
        $this->assertNotSame($firstId, $secondId);
    }

    public function testToDatabaseName(): void
    {
        $this->assertSame('abcd_efgh_ijkl_mnop', IdGenerator::toDatabaseName('abcd-efgh-ijkl-mnop'));
    }

    public function testToDatabaseUser(): void
    {
        $this->assertSame('shpd_abcdefgh', IdGenerator::toDatabaseUser('abcd-efgh-ijkl-mnop'));
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
