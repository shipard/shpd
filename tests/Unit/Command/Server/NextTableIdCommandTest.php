<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\NextTableIdCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableNextTableIdCommand extends NextTableIdCommand
{
    public function __construct(private string $modulesBasePath)
    {
        parent::__construct();
    }

    protected function getModulesBasePath(): string
    {
        return $this->modulesBasePath;
    }
}

class NextTableIdCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_next_table_id_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function createCommandTester(): CommandTester
    {
        $command = new TestableNextTableIdCommand($this->tempDir);
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    private function createTableFile(string $group, string $module, string $table, mixed $tableId): void
    {
        $dir = $this->tempDir . '/' . $group . '/' . $module . '/tables';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $content = is_int($tableId)
            ? '{"tableId": ' . $tableId . ', "name": "Test"}'
            : '{"name": "Test"}';
        file_put_contents($dir . '/' . $table . '.jsonc', $content);
    }

    public function testNoModulesReturnsOne(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1', trim($tester->getDisplay()));
    }

    public function testReturnsNextAfterExisting(): void
    {
        $this->createTableFile('core', 'system', 'core_system_users', 1);
        $this->createTableFile('core', 'system', 'core_system_sessions', 2);
        $this->createTableFile('core', 'system', 'core_system_settings', 3);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('4', trim($tester->getDisplay()));
    }

    public function testSkipsNonIntTableId(): void
    {
        $this->createTableFile('core', 'system', 'core_system_users', 1);
        // File without tableId
        $dir = $this->tempDir . '/core/system/tables';
        file_put_contents($dir . '/core_system_nokey.jsonc', '{"name": "No tableId here"}');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('2', trim($tester->getDisplay()));
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
