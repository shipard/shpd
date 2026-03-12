<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\Utils\IdGenerator;

class DatabaseManagerTest extends TestCase
{
    public function testGeneratePasswordLength(): void
    {
        $config = $this->createMockedServerConfig();
        $manager = $this->createPartialMock(DatabaseManager::class, ['__construct']);

        // Use reflection to test generatePassword without DB connection
        $reflection = new \ReflectionClass(DatabaseManager::class);
        $method = $reflection->getMethod('generatePassword');

        // Create instance without calling constructor
        $instance = $reflection->newInstanceWithoutConstructor();
        $password = $method->invoke($instance);

        $this->assertGreaterThanOrEqual(32, strlen($password));
    }

    public function testGeneratePasswordContainsVariedChars(): void
    {
        $reflection = new \ReflectionClass(DatabaseManager::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('generatePassword');

        $password = $method->invoke($instance);

        // Password should contain at least letters and digits
        $this->assertMatchesRegularExpression('/[a-zA-Z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
    }

    public function testGeneratePasswordIsUnique(): void
    {
        $reflection = new \ReflectionClass(DatabaseManager::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('generatePassword');

        $passwords = [];
        for ($i = 0; $i < 10; $i++) {
            $password = $method->invoke($instance);
            $this->assertNotContains($password, $passwords);
            $passwords[] = $password;
        }
    }

    public function testIdGeneratorToDatabaseName(): void
    {
        $this->assertSame('abcd_efgh_ijkl_mnop', IdGenerator::toDatabaseName('abcd-efgh-ijkl-mnop'));
    }

    public function testIdGeneratorToDatabaseUser(): void
    {
        $this->assertSame('shpd_abcdefgh', IdGenerator::toDatabaseUser('abcd-efgh-ijkl-mnop'));
    }

    private function createMockedServerConfig(): MockObject
    {
        $config = $this->createMock(ServerConfig::class);
        $config->method('getHost')->willReturn('127.0.0.1');
        $config->method('getPort')->willReturn(3306);
        $config->method('getAdminUser')->willReturn('root');
        $config->method('getAdminPassword')->willReturn('secret');
        return $config;
    }
}
