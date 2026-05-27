<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\ApiKeyCreateCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableApiKeyCreateCommand extends ApiKeyCreateCommand
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

class ApiKeyCreateCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_apikey_create_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getId')->willReturn('abcd-efgh-ijkl-mnop');

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableApiKeyCreateCommand($this->dsConfig, $this->dsConnection, $this->tempDir);
        (new Application())->add($command);
        return new CommandTester($command);
    }

    /**
     * Stub findUserMatches() pomocí ApiKeyService: ten zavolá fetchRow třikrát
     * (id / login / email) a sloučí. Pro test stačí vrátit row jen pro
     * očekávanou cestu (login).
     */
    private function stubUserLookup(?array $userRow): void
    {
        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (string $sql) use ($userRow): ?array {
                if (str_contains($sql, 'core_system_users') && str_contains($sql, 'login = %s')) {
                    return $userRow;
                }
                return null;
            },
        );
    }

    public function testHappyPathCreatesKey(): void
    {
        $this->stubUserLookup(['id' => 5, 'login' => 'alice', 'email' => 'a@x']);

        $captured = null;
        $this->dsConnection->expects($this->once())
            ->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $this->assertSame('core_system_api_keys', $table);
                $captured = $data;
                return 42;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'alice', '--name' => 'import-x']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/shpd_ak_[0-9a-f]{32}/', $output);
        $this->assertStringContainsString('Key ID:       42', $output);
        $this->assertStringContainsString('Key name:     import-x', $output);
        $this->assertStringContainsString('User:         alice (id=5)', $output);
        $this->assertStringContainsString('Allowed IPs:  (none)', $output);
        $this->assertStringContainsString('Expires:      (never)', $output);

        $this->assertSame(5, $captured['user_id']);
        $this->assertSame('import-x', $captured['name']);
        $this->assertSame(1, $captured['is_active']);
        $this->assertNull($captured['allowed_ips']);
        $this->assertNull($captured['expires_at']);
    }

    public function testResolvesUserByNumericId(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (string $sql, $param): ?array {
                if (str_contains($sql, 'id = %i') && $param === 7) {
                    return ['id' => 7, 'login' => 'bob', 'email' => 'b@x'];
                }
                return null;
            },
        );
        $this->dsConnection->method('insertRow')->willReturn(1);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => '7', '--name' => 'k']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('User:         bob (id=7)', $tester->getDisplay());
    }

    public function testResolvesUserByEmail(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (string $sql, $param): ?array {
                if (str_contains($sql, 'email = %s') && $param === 'a@x') {
                    return ['id' => 5, 'login' => 'alice', 'email' => 'a@x'];
                }
                return null;
            },
        );
        $this->dsConnection->method('insertRow')->willReturn(1);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'a@x', '--name' => 'k']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('User:         alice (id=5)', $tester->getDisplay());
    }

    public function testFailsWhenUserNotFound(): void
    {
        $this->stubUserLookup(null);
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'ghost', '--name' => 'k']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("User 'ghost' not found", $tester->getDisplay());
    }

    public function testFailsWhenUserAmbiguous(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (string $sql, $param): ?array {
                // 'bob' matchne login → user 1; současně 'bob' je něčí email → user 2
                if (str_contains($sql, 'login = %s') && $param === 'bob') {
                    return ['id' => 1, 'login' => 'bob', 'email' => 'b1@x'];
                }
                if (str_contains($sql, 'email = %s') && $param === 'bob') {
                    return ['id' => 2, 'login' => 'bob_alt', 'email' => 'bob'];
                }
                return null;
            },
        );
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'bob', '--name' => 'k']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('ambiguous', $tester->getDisplay());
    }

    public function testFailsWhenUserOptionMissing(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--name' => 'k']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--user is required', $tester->getDisplay());
    }

    public function testFailsWhenNameOptionMissing(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'alice']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--name is required', $tester->getDisplay());
    }

    public function testFailsWhenNameTooLong(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--user' => 'alice', '--name' => str_repeat('x', 101)]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--name must be at most 100', $tester->getDisplay());
    }

    public function testAcceptsMultipleIps(): void
    {
        $this->stubUserLookup(['id' => 5, 'login' => 'alice', 'email' => 'a@x']);

        $captured = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user' => 'alice',
            '--name' => 'k',
            '--ip'   => ['1.2.3.4', '5.6.7.8'],
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(json_encode(['1.2.3.4', '5.6.7.8']), $captured['allowed_ips']);
        $this->assertStringContainsString('Allowed IPs:  1.2.3.4, 5.6.7.8', $tester->getDisplay());
    }

    public function testAcceptsCommaSeparatedIps(): void
    {
        $this->stubUserLookup(['id' => 5, 'login' => 'alice', 'email' => 'a@x']);

        $captured = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user' => 'alice',
            '--name' => 'k',
            '--ip'   => ['1.2.3.4,5.6.7.8'],
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(json_encode(['1.2.3.4', '5.6.7.8']), $captured['allowed_ips']);
    }

    public function testRejectsInvalidIp(): void
    {
        $this->stubUserLookup(['id' => 5, 'login' => 'alice', 'email' => 'a@x']);
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user' => 'alice',
            '--name' => 'k',
            '--ip'   => ['not-an-ip'],
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("'not-an-ip' is not a valid IP", $tester->getDisplay());
    }

    public function testAcceptsAbsoluteExpiresDate(): void
    {
        $this->stubUserLookup(['id' => 5, 'login' => 'alice', 'email' => 'a@x']);

        $captured = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user'    => 'alice',
            '--name'    => 'k',
            '--expires' => '2026-12-31 23:59:59',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('2026-12-31 23:59:59', $captured['expires_at']);
    }

    public function testAcceptsRelativeExpires(): void
    {
        $this->stubUserLookup(['id' => 5, 'login' => 'alice', 'email' => 'a@x']);

        $captured = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $tester = $this->tester();
        $before = new \DateTimeImmutable('+30 days');
        $exitCode = $tester->execute([
            '--user'    => 'alice',
            '--name'    => 'k',
            '--expires' => '+30d',
        ]);
        $after = new \DateTimeImmutable('+30 days');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNotNull($captured['expires_at']);
        $resolved = new \DateTimeImmutable($captured['expires_at']);
        $this->assertGreaterThanOrEqual($before->getTimestamp() - 5, $resolved->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp() + 5, $resolved->getTimestamp());
    }

    public function testRejectsInvalidExpires(): void
    {
        $this->stubUserLookup(['id' => 5, 'login' => 'alice', 'email' => 'a@x']);
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user'    => 'alice',
            '--name'    => 'k',
            '--expires' => 'not-a-date',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("'not-a-date' is not a valid date", $tester->getDisplay());
    }

    public function testFailsWhenNotInDataSourceDir(): void
    {
        // create a fresh empty dir without config/main.json — but use it without injected mocks
        $emptyDir = sys_get_temp_dir() . '/shpd_apikey_empty_' . uniqid();
        mkdir($emptyDir, 0755);

        try {
            // Use command without injected config/connection so the dsDir check fires.
            $command = new class($emptyDir) extends ApiKeyCreateCommand {
                public function __construct(private readonly string $dsDir)
                {
                    parent::__construct();
                }
                protected function getDataSourceDir(): string
                {
                    return $this->dsDir;
                }
            };
            (new Application())->add($command);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute(['--user' => 'alice', '--name' => 'k']);

            $this->assertSame(Command::FAILURE, $exitCode);
            $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
        } finally {
            rmdir($emptyDir);
        }
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
