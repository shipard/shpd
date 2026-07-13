<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\MailRelayConfig;

class MailRelayConfigTest extends TestCase
{
    public function testFromArrayWithDefaults(): void
    {
        $relay = MailRelayConfig::fromArray(['host' => 'smtp.example.com']);

        $this->assertSame('smtp.example.com', $relay->host);
        $this->assertSame(587, $relay->port);
        $this->assertSame('starttls', $relay->security);
        $this->assertNull($relay->username);
        $this->assertNull($relay->password);
    }

    public function testFromArrayFull(): void
    {
        $relay = MailRelayConfig::fromArray([
            'host'     => 'smtp.example.com',
            'port'     => 465,
            'security' => 'tls',
            'username' => 'mailer',
            'password' => 's3cret',
        ]);

        $this->assertSame(465, $relay->port);
        $this->assertSame('tls', $relay->security);
        $this->assertSame('mailer', $relay->username);
        $this->assertSame('s3cret', $relay->password);
    }

    public function testMissingHostThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/host/');

        MailRelayConfig::fromArray(['port' => 25]);
    }

    public function testInvalidSecurityThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/security/');

        MailRelayConfig::fromArray(['host' => 'smtp.example.com', 'security' => 'ssl']);
    }

    public function testInvalidPortThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/port/');

        MailRelayConfig::fromArray(['host' => 'smtp.example.com', 'port' => 0]);
    }

    public function testSecurityNoneForLocalhostPostfix(): void
    {
        $relay = MailRelayConfig::fromArray([
            'host'     => 'localhost',
            'port'     => 25,
            'security' => 'none',
        ]);

        $this->assertSame('none', $relay->security);
        $this->assertSame(25, $relay->port);
    }
}
