<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form\Lookup;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\Lookup\LookupItem;

class LookupItemTest extends TestCase
{
    public function testIntIdWithBothLines(): void
    {
        $item = new LookupItem(id: 42, primary: 'Testování 999', secondary: 'IČO 12345678');

        $this->assertSame(42, $item->id);
        $this->assertSame('Testování 999', $item->primary);
        $this->assertSame('IČO 12345678', $item->secondary);
    }

    public function testStringIdSupported(): void
    {
        $item = new LookupItem(id: 'CZK', primary: 'Česká koruna');

        $this->assertSame('CZK', $item->id);
        $this->assertSame('Česká koruna', $item->primary);
        $this->assertNull($item->secondary);
    }

    public function testSecondaryDefaultsToNull(): void
    {
        $item = new LookupItem(id: 1, primary: 'A');

        $this->assertNull($item->secondary);
    }

    public function testToArrayWithSecondary(): void
    {
        $item = new LookupItem(id: 42, primary: 'P', secondary: 'S');

        $this->assertSame(
            ['id' => 42, 'primary' => 'P', 'secondary' => 'S'],
            $item->toArray(),
        );
    }

    public function testToArrayKeepsSecondaryKeyAsNull(): void
    {
        $item = new LookupItem(id: 1, primary: 'P');

        $this->assertSame(
            ['id' => 1, 'primary' => 'P', 'secondary' => null],
            $item->toArray(),
        );
    }
}
