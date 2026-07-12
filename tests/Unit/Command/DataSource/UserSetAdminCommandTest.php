<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\UserSetAdminCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableUserSetAdminCommand extends UserSetAdminCommand
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

class UserSetAdminCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_usersetadmin_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function createCommandTester(): CommandTester
    {
        $command = new TestableUserSetAdminCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    /**
     * @param array<string,mixed>|null $user       Row returned for the user lookup
     * @param int                      $adminCount Result of the active-admin count query
     */
    private function mockQueries(?array $user, int $adminCount = 1): void
    {
        $this->dsConnection->method('fetchRow')
            ->willReturnCallback(function (string $sql) use ($user, $adminCount): ?array {
                if (str_contains($sql, 'COUNT(*)')) {
                    return ['cnt' => $adminCount];
                }
                return $user;
            });
    }

    public function testGrantAdmin(): void
    {
        $this->mockQueries(['id' => 7, 'login' => 'jane', 'is_admin' => 0, 'is_active' => 1]);

        $captured = null;
        $this->dsConnection->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured): void {
                $captured = $args;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--login' => 'jane']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString("Admin rights granted to user 'jane'.", $tester->getDisplay());
        $this->assertNotNull($captured);
        $this->assertSame(1, $captured[1]); // is_admin value
        $this->assertSame(7, $captured[2]); // user id
    }

    public function testRevokeAdminWithAnotherActiveAdmin(): void
    {
        $this->mockQueries(['id' => 7, 'login' => 'jane', 'is_admin' => 1, 'is_active' => 1], adminCount: 2);

        $captured = null;
        $this->dsConnection->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured): void {
                $captured = $args;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--login' => 'jane', '--revoke' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString("Admin rights revoked from user 'jane'.", $tester->getDisplay());
        $this->assertNotNull($captured);
        $this->assertSame(0, $captured[1]);
    }

    public function testRevokeLastActiveAdminIsRejected(): void
    {
        $this->mockQueries(['id' => 7, 'login' => 'jane', 'is_admin' => 1, 'is_active' => 1], adminCount: 1);
        $this->dsConnection->expects($this->never())->method('execute');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--login' => 'jane', '--revoke' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('last active admin', $tester->getDisplay());
    }

    public function testRevokeInactiveAdminBypassesLastAdminCheck(): void
    {
        // Neaktivní admin se do pojistky nepočítá — jeho revoke nesmí být blokován.
        $this->mockQueries(['id' => 7, 'login' => 'jane', 'is_admin' => 1, 'is_active' => 0], adminCount: 0);

        $executed = false;
        $this->dsConnection->method('execute')
            ->willReturnCallback(function () use (&$executed): void {
                $executed = true;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--login' => 'jane', '--revoke' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue($executed);
    }

    public function testGrantToAlreadyAdminIsNoop(): void
    {
        $this->mockQueries(['id' => 7, 'login' => 'jane', 'is_admin' => 1, 'is_active' => 1]);
        $this->dsConnection->expects($this->never())->method('execute');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--login' => 'jane']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('already an admin', $tester->getDisplay());
    }

    public function testUnknownLoginFails(): void
    {
        $this->mockQueries(null);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--login' => 'ghost']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("User with login 'ghost' not found.", $tester->getDisplay());
    }

    public function testMissingLoginOption(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--login is required', $tester->getDisplay());
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
