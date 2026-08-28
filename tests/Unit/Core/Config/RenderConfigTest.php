<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\RenderConfig;

class RenderConfigTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $config = RenderConfig::fromArray(['url' => 'http://127.0.0.1:3000']);

        $this->assertSame('http://127.0.0.1:3000', $config->url);
        $this->assertSame(30, $config->timeoutSec);
    }

    public function testFromArrayCustomTimeout(): void
    {
        $config = RenderConfig::fromArray(['url' => 'http://render.internal:3000', 'timeoutSec' => 60]);

        $this->assertSame(60, $config->timeoutSec);
    }

    public function testFromArrayStripsTrailingSlash(): void
    {
        $config = RenderConfig::fromArray(['url' => 'http://127.0.0.1:3000/']);

        $this->assertSame('http://127.0.0.1:3000', $config->url);
    }

    public function testMissingUrlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/url/');

        RenderConfig::fromArray([]);
    }

    public function testNonStringUrlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/url.*string/i');

        RenderConfig::fromArray(['url' => ['nested' => 'object']]);
    }

    public function testNonHttpSchemeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/http/');

        RenderConfig::fromArray(['url' => 'ftp://render.internal']);
    }

    public function testNonPositiveTimeoutThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/timeoutSec/');

        RenderConfig::fromArray(['url' => 'http://127.0.0.1:3000', 'timeoutSec' => 0]);
    }
}
