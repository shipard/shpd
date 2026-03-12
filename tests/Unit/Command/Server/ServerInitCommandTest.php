<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\ServerInitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableServerInitCommand extends ServerInitCommand
{
    public bool $rootResult = false;
    public bool $mysqladminResult = true;
    public ?string $lastMysqladminPassword = null;

    public function __construct(string $tempConfigPath)
    {
        parent::__construct();
        $this->serverConfigPath = $tempConfigPath;
    }

    protected function isRunningAsRoot(): bool
    {
        return $this->rootResult;
    }

    protected function generatePassword(): string
    {
        return 'TestPassword1234567890!@#$%^&*';
    }

    protected function runMysqladmin(string $password): bool
    {
        $this->lastMysqladminPassword = $password;
        return $this->mysqladminResult;
    }
}

class ServerInitCommandTest extends TestCase
{
    private string $tempConfigPath;

    protected function setUp(): void
    {
        $this->tempConfigPath = sys_get_temp_dir() . '/shipard_server_init_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempConfigPath)) {
            unlink($this->tempConfigPath);
        }
    }

    private function createTester(TestableServerInitCommand $command): CommandTester
    {
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    public function testFailsWhenNotRoot(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = false;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must be run as root', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tempConfigPath);
    }

    public function testSucceedsWhenAlreadyInitialized(): void
    {
        file_put_contents($this->tempConfigPath, '{}');

        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('already initialized', $tester->getDisplay());
        $this->assertNull($command->lastMysqladminPassword);
    }

    public function testSuccessfulInit(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('initialized successfully', $tester->getDisplay());
        $this->assertFileExists($this->tempConfigPath);

        $perms = fileperms($this->tempConfigPath) & 0777;
        $this->assertSame(0600, $perms);

        $config = json_decode(file_get_contents($this->tempConfigPath), true);
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(3306, $config['port']);
        $this->assertSame('root', $config['admin_user']);
        $this->assertSame('production', $config['mode']);
        $this->assertSame('TestPassword1234567890!@#$%^&*', $config['admin_password']);

        $this->assertSame('TestPassword1234567890!@#$%^&*', $command->lastMysqladminPassword);
    }

    public function testMysqladminFailure(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;
        $command->mysqladminResult = false;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to set MariaDB root password', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tempConfigPath);
    }
}
