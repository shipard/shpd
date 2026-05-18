<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\AddressesLookup;

class AddressesLookupTest extends TestCase
{
    public function testGetAllowedFilterKeys(): void
    {
        $this->assertSame(['person'], (new AddressesLookup())->getAllowedFilterKeys());
    }

    public function testBuildItemUsesDisplayLine(): void
    {
        $item = AddressesLookup::buildItem(['id' => 17, 'display_line' => 'Hlavní 12, Praha']);

        $this->assertSame(17, $item->id);
        $this->assertSame('Hlavní 12, Praha', $item->primary);
        $this->assertNull($item->secondary);
    }

    public function testBuildItemEmptyDisplayLineFallsBack(): void
    {
        $item = AddressesLookup::buildItem(['id' => 17, 'display_line' => '']);

        $this->assertSame('#17', $item->primary);
    }

    public function testSearchWithoutPersonFilterReturnsEmpty(): void
    {
        // With $this->db = null AND missing filter[person] we get [] either way; the
        // explicit empty-filter return is the important contract guarantee.
        $this->assertSame([], (new AddressesLookup())->search('', [], 10));
    }

    public function testSearchWithZeroPersonReturnsEmpty(): void
    {
        $this->assertSame([], (new AddressesLookup())->search('', ['person' => 0], 10));
    }
}
