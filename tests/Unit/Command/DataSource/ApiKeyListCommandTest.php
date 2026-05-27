<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\ApiKeyListCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableApiKeyListCommand extends ApiKeyListCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class ApiKeyListCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_apikey_list_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableApiKeyListCommand($this->dsConfig, $this->dsConnection, $this->tempDir);
        (new Application())->add($command);
        return new CommandTester($command);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'           => 42,
            'user_id'      => 5,
            'user_login'   => 'alice',
            'name'         => 'integration-x',
            'key_prefix'   => 'aabbccdd1122',
            'is_active'    => 1,
            'expires_at'   => null,
            'last_used_at' => null,
            'created'      => '2026-05-27 14:30:00',
            'modified'     => '2026-05-27 14:30:00',
        ], $overrides);
    }

    public function testDefaultFilterShowsOnlyActive(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $this->dsConnection->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [$this->row()];
            },
        );

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('k.is_active = %i', $capturedSql);
        $this->assertSame([1], $capturedParams);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('42', $output);
        $this->assertStringContainsString('alice', $output);
        $this->assertStringContainsString('integration-x', $output);
        $this->assertStringContainsString('aabbccdd1122', $output);
        $this->assertStringContainsString('yes', $output);
        $this->assertStringContainsString('(never)', $output);
        $this->assertStringContainsString('2026-05-27 14:30:00', $output);
    }

    public function testIncludeInactiveFlag(): void
    {
        $capturedSql = null;
        $this->dsConnection->method('fetchAll')->willReturnCallback(
            function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;
                return [
                    $this->row(['id' => 1, 'is_active' => 1]),
                    $this->row(['id' => 2, 'is_active' => 0, 'name' => 'revoked-k']),
                ];
            },
        );

        $tester = $this->tester();
        $exitCode = $tester->execute(['--include-inactive' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('k.is_active', $capturedSql);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('yes', $output);
        $this->assertStringContainsString('no', $output);
        $this->assertStringContainsString('revoked-k', $output);
    }

    public function testFilterByUserResolvesToId(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (string $sql, $param): ?array {
                if (str_contains($sql, 'login = %s') && $param === 'alice') {
                    return ['id' => 5, 'login' => 'alice', 'email' => 'a@x'];
                }
                return null;
            },
        );

        $capturedParams = null;
        $this->dsConnection->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use (&$capturedParams): array {
                $capturedParams = $params;
                return [$this->row()];
            },
        );

        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'alice']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertContains(5, $capturedParams);
    }

    public function testFailsWhenFilterUserNotFound(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->expects($this->never())->method('fetchAll');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'ghost']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("User 'ghost' not found", $tester->getDisplay());
    }

    public function testEmptyResultMessageActiveOnly(): void
    {
        $this->dsConnection->method('fetchAll')->willReturn([]);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No active API keys found.', $tester->getDisplay());
    }

    public function testEmptyResultMessageIncludeInactive(): void
    {
        $this->dsConnection->method('fetchAll')->willReturn([]);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--include-inactive' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No API keys found.', $tester->getDisplay());
    }

    public function testTruncatesLongLogin(): void
    {
        $this->dsConnection->method('fetchAll')->willReturn([
            $this->row(['user_login' => 'verylongloginname']),
        ]);

        $tester = $this->tester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('verylonglo…', $output);
        $this->assertStringNotContainsString('verylongloginname', $output);
    }

    public function testFormatsIsoDatetimesAsSpacedForm(): void
    {
        // DataSourceConnection::normalizeValue() vrací DATETIME jako 'Y-m-d\TH:i:s'
        $this->dsConnection->method('fetchAll')->willReturn([
            $this->row([
                'expires_at'   => '2026-12-31T23:59:59',
                'last_used_at' => '2026-05-27T16:42:00',
                'created'      => '2026-05-27T14:30:00',
            ]),
        ]);

        $tester = $this->tester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('2026-12-31 23:59:59', $output);
        $this->assertStringContainsString('2026-05-27 16:42:00', $output);
        $this->assertStringContainsString('2026-05-27 14:30:00', $output);
        $this->assertStringNotContainsString('2026-12-31T23:59:59', $output);
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
