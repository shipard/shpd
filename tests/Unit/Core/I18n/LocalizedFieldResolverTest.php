<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\I18n;

use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\LocalizedFieldResolver;

class LocalizedFieldResolverTest extends TestCase
{
    public function testDirectHit(): void
    {
        $data = ['name:cs' => 'Doklady', 'name' => 'Docs'];
        $this->assertSame('Doklady', LocalizedFieldResolver::resolve($data, 'name', 'cs'));
    }

    public function testFallbackToEn(): void
    {
        $data = ['name:en' => 'Docs', 'name' => 'Documents'];
        $this->assertSame('Docs', LocalizedFieldResolver::resolve($data, 'name', 'de'));
    }

    public function testFallbackToBare(): void
    {
        $data = ['name' => 'Documents'];
        $this->assertSame('Documents', LocalizedFieldResolver::resolve($data, 'name', 'cs'));
    }

    public function testMissingFieldReturnsEmpty(): void
    {
        $this->assertSame('', LocalizedFieldResolver::resolve([], 'name', 'cs'));
    }
}
