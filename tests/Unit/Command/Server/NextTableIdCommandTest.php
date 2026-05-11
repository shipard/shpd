<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\NextTableIdCommand;
use Shipard\Core\Module\ModulePathResolver;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableNextTableIdCommand extends NextTableIdCommand
{
    /** @param list<string> $roots */
    public function __construct(private array $roots)
    {
        parent::__construct();
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver($this->roots);
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

    private function makeRoot(string $name): string
    {
        $path = $this->tempDir . '/' . $name;
        mkdir($path, 0755, true);
        return $path;
    }

    /**
     * @param list<string> $roots
     */
    private function createCommandTester(array $roots): CommandTester
    {
        $command = new TestableNextTableIdCommand($roots);
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    /**
     * Creates `{root}/{group}/{module}/tables/{table}.jsonc` with the given
     * tableId, plus a stub `module.jsonc` so ModulePathResolver discovers
     * the module.
     */
    private function createTableFile(string $root, string $group, string $module, string $table, mixed $tableId): void
    {
        $moduleDir = $root . '/' . $group . '/' . $module;
        $tablesDir = $moduleDir . '/tables';
        if (!is_dir($tablesDir)) {
            mkdir($tablesDir, 0755, true);
        }
        if (!is_file($moduleDir . '/module.jsonc')) {
            file_put_contents($moduleDir . '/module.jsonc', '');
        }
        $content = is_int($tableId)
            ? '{"tableId": ' . $tableId . ', "name": "Test"}'
            : '{"name": "Test"}';
        file_put_contents($tablesDir . '/' . $table . '.jsonc', $content);
    }

    public function testNoModulesReturnsOne(): void
    {
        $root = $this->makeRoot('main');
        $tester = $this->createCommandTester([$root]);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('1', trim($tester->getDisplay()));
    }

    public function testReturnsNextAfterExisting(): void
    {
        $root = $this->makeRoot('main');
        $this->createTableFile($root, 'core', 'system', 'core_system_users', 1);
        $this->createTableFile($root, 'core', 'system', 'core_system_sessions', 2);
        $this->createTableFile($root, 'core', 'system', 'core_system_settings', 3);

        $tester = $this->createCommandTester([$root]);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('4', trim($tester->getDisplay()));
    }

    public function testSkipsNonIntTableId(): void
    {
        $root = $this->makeRoot('main');
        $this->createTableFile($root, 'core', 'system', 'core_system_users', 1);
        // File without tableId
        $tablesDir = $root . '/core/system/tables';
        file_put_contents($tablesDir . '/core_system_nokey.jsonc', '{"name": "No tableId here"}');

        $tester = $this->createCommandTester([$root]);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('2', trim($tester->getDisplay()));
    }

    public function testScansMultipleRoots(): void
    {
        $main  = $this->makeRoot('main');
        $extra = $this->makeRoot('extra');
        $this->createTableFile($main,  'core',    'system', 'core_system_t', 5);
        $this->createTableFile($extra, 'partner', 'crm',    'partner_crm_t', 10);

        $tester = $this->createCommandTester([$main, $extra]);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('11', trim($tester->getDisplay()));
    }

    public function testRangeReturnsFirstFree(): void
    {
        $root = $this->makeRoot('main');
        $this->createTableFile($root, 'cust', 'a', 't1', 10000);
        $this->createTableFile($root, 'cust', 'a', 't2', 10001);
        $this->createTableFile($root, 'cust', 'a', 't3', 10003);

        $tester = $this->createCommandTester([$root]);
        $exitCode = $tester->execute(['--range' => '10000:10099']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('10002', trim($tester->getDisplay()));
    }

    public function testRangeReturnsLowestWhenEmpty(): void
    {
        $root = $this->makeRoot('main');
        // No tableIds anywhere — empty root.
        $tester = $this->createCommandTester([$root]);
        $exitCode = $tester->execute(['--range' => '10000:10099']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('10000', trim($tester->getDisplay()));
    }

    public function testRangeSkipsIdsOutsideRange(): void
    {
        $root = $this->makeRoot('main');
        $this->createTableFile($root, 'core', 'a', 't1', 5);
        $this->createTableFile($root, 'core', 'b', 't2', 12000);

        $tester = $this->createCommandTester([$root]);
        $exitCode = $tester->execute(['--range' => '10000:10099']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('10000', trim($tester->getDisplay()));
    }

    public function testRangeFullReturnsFailure(): void
    {
        $root = $this->makeRoot('main');
        $this->createTableFile($root, 'core', 'a', 't100', 100);
        $this->createTableFile($root, 'core', 'a', 't101', 101);
        $this->createTableFile($root, 'core', 'a', 't102', 102);
        $this->createTableFile($root, 'core', 'a', 't103', 103);

        $tester = $this->createCommandTester([$root]);
        $exitCode = $tester->execute(['--range' => '100:103']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No free tableId', $tester->getDisplay());
    }

    public function testRangeInvalidFormat(): void
    {
        $root = $this->makeRoot('main');
        $bad = ['abc', '10:5', '0:5', '100:70000', ':10', '10:', '10-20'];

        foreach ($bad as $raw) {
            $tester = $this->createCommandTester([$root]);
            $exitCode = $tester->execute(['--range' => $raw]);

            $this->assertSame(1, $exitCode, "Expected FAILURE for --range='$raw'");
            $this->assertStringContainsString(
                'Invalid --range format',
                $tester->getDisplay(),
                "Expected error message for --range='$raw'",
            );
        }
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
