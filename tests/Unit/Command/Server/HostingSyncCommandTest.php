<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\HostingSyncCommand;
use Shipard\Core\Config\ServerConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class HostingSyncCommandTest extends TestCase
{
    public function testMissingHostingSectionIsInformativeSuccess(): void
    {
        $config = $this->createMock(ServerConfig::class);
        $config->method('load');
        $config->method('getHosting')->willReturn(null);

        $tester = new CommandTester(new HostingSyncCommand($config));
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('No hosting section', $tester->getDisplay());
    }

    public function testInvalidHostingSectionFails(): void
    {
        $config = $this->createMock(ServerConfig::class);
        $config->method('load');
        $config->method('getHosting')->willThrowException(new \RuntimeException("Hosting config: 'apiKey' must start with shpd_hk_"));

        $tester = new CommandTester(new HostingSyncCommand($config));
        $exit = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Invalid hosting config', $tester->getDisplay());
    }

    public function testUnloadableServerConfigFails(): void
    {
        $config = $this->createMock(ServerConfig::class);
        $config->method('load')->willThrowException(new \RuntimeException('Config file not found'));

        $tester = new CommandTester(new HostingSyncCommand($config));
        $exit = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Failed to load server config', $tester->getDisplay());
    }
}
