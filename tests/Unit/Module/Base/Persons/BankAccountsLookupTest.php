<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\BankAccountsLookup;

class BankAccountsLookupTest extends TestCase
{
    public function testGetAllowedFilterKeys(): void
    {
        $this->assertSame(['person'], (new BankAccountsLookup())->getAllowedFilterKeys());
    }

    public function testBuildItemAccountNumberAndIban(): void
    {
        $item = BankAccountsLookup::buildItem([
            'id'             => 5,
            'account_number' => '123456789/0100',
            'iban'           => 'CZ6508000000192000145399',
        ]);

        $this->assertSame(5, $item->id);
        $this->assertSame('123456789/0100', $item->primary);
        $this->assertSame('IBAN CZ6508000000192000145399', $item->secondary);
    }

    public function testBuildItemIbanOnlyShowsAsPrimaryWithoutDuplicateSecondary(): void
    {
        $item = BankAccountsLookup::buildItem([
            'id'             => 7,
            'account_number' => '',
            'iban'           => 'CZ6508000000192000145399',
        ]);

        $this->assertSame('CZ6508000000192000145399', $item->primary);
        $this->assertNull($item->secondary);
    }

    public function testBuildItemAccountNumberOnly(): void
    {
        $item = BankAccountsLookup::buildItem([
            'id'             => 1,
            'account_number' => '123456789/0100',
            'iban'           => null,
        ]);

        $this->assertSame('123456789/0100', $item->primary);
        $this->assertNull($item->secondary);
    }

    public function testBuildItemNoIdentifierFallsBack(): void
    {
        $item = BankAccountsLookup::buildItem([
            'id'             => 9,
            'account_number' => '',
            'iban'           => null,
        ]);

        $this->assertSame('#9', $item->primary);
    }

    public function testSearchWithoutPersonFilterReturnsEmpty(): void
    {
        $this->assertSame([], (new BankAccountsLookup())->search('', [], 10));
    }
}
