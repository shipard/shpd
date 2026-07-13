<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\UserCreateCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableUserCreateCommand extends UserCreateCommand
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

class UserCreateCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_usercreate_test_' . uniqid();
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
        $command = new TestableUserCreateCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testSuccessfulUserCreation(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->method('insertRow')->willReturn(42);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'admin',
            '--password' => 'heslo123',
            '--name'     => 'Administrator',
            '--email'    => 'admin@example.com',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('User created successfully.', $display);
        $this->assertStringContainsString('ID:    42', $display);
        $this->assertStringContainsString('Login: admin', $display);
        $this->assertStringContainsString('Name:  Administrator', $display);
        $this->assertStringContainsString('Email: admin@example.com', $display);
    }

    public function testSuccessfulUserCreationWithoutEmail(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->method('insertRow')->willReturn(1);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'jane',
            '--password' => 'secret',
            '--name'     => 'Jane Doe',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('(none)', $tester->getDisplay());
    }

    public function testDuplicateLoginIsRejected(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 5]);
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'admin',
            '--password' => 'pass',
            '--name'     => 'Admin',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("User with login 'admin' already exists.", $tester->getDisplay());
    }

    public function testMissingLoginOption(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--password' => 'pass',
            '--name'     => 'Admin',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--login is required', $tester->getDisplay());
    }

    public function testMissingPasswordCreatesUserWithoutLocalPassword(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);

        $captured = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 5;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'novy',
            '--name'  => 'Nový uživatel',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNull($captured['password_hash']);
        $this->assertStringContainsString('send an invitation', $tester->getDisplay());
    }

    public function testMissingNameOption(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'admin',
            '--password' => 'pass',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--name is required', $tester->getDisplay());
    }

    public function testPasswordIsHashed(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);

        $capturedData = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1;
            });

        $tester = $this->createCommandTester();
        $tester->execute([
            '--login'    => 'admin',
            '--password' => 'plaintext_password',
            '--name'     => 'Admin',
        ]);

        $this->assertNotNull($capturedData);
        $this->assertNotSame('plaintext_password', $capturedData['password_hash']);
        $this->assertTrue(password_verify('plaintext_password', $capturedData['password_hash']));
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
