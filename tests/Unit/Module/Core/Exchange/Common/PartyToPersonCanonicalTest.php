<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Common;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Common\PartyToPersonCanonical;

class PartyToPersonCanonicalTest extends TestCase
{
    public function testMinimalPartyProducesMinimalPersonCanonical(): void
    {
        $canonical = PartyToPersonCanonical::toPersonCanonical([
            'name' => 'Acme s.r.o.',
        ]);

        $this->assertSame('shpd.persons.person', $canonical['format']);
        $this->assertSame('1.0', $canonical['formatVersion']);
        $this->assertSame('company', $canonical['personType']);
        $this->assertSame('cz', $canonical['country'], 'country defaults to cz when missing');
        $this->assertSame(['fullName' => 'Acme s.r.o.'], $canonical['name']);
        $this->assertArrayNotHasKey('companyId', $canonical);
        $this->assertArrayNotHasKey('taxId', $canonical);
    }

    public function testFullPartyMapsAllIdentifiers(): void
    {
        $canonical = PartyToPersonCanonical::toPersonCanonical([
            'name'              => 'Acme s.r.o.',
            'country'           => 'cz',
            'companyId'         => '12345678',
            'taxId'             => '12345678',
            'vatId'             => 'CZ12345678',
            'courtRegistration' => 'MS Praha C 12345',
            'govEBoxId'         => 'abcd1ef',
        ]);

        $this->assertSame('12345678', $canonical['companyId']);
        $this->assertSame('12345678', $canonical['taxId']);
        $this->assertSame('CZ12345678', $canonical['vatId']);
        $this->assertSame('MS Praha C 12345', $canonical['courtRegistration']);
        $this->assertSame('abcd1ef', $canonical['govEBoxId']);
    }

    public function testCountryLowercased(): void
    {
        $canonical = PartyToPersonCanonical::toPersonCanonical([
            'name'    => 'Acme s.r.o.',
            'country' => 'CZ',
        ]);

        $this->assertSame('cz', $canonical['country']);
    }

    public function testEmptyStringFieldsAreOmitted(): void
    {
        $canonical = PartyToPersonCanonical::toPersonCanonical([
            'name'      => 'Acme s.r.o.',
            'companyId' => '',
            'taxId'     => '   ',
            'vatId'     => null,
        ]);

        $this->assertArrayNotHasKey('companyId', $canonical);
        $this->assertArrayNotHasKey('taxId', $canonical);
        $this->assertArrayNotHasKey('vatId', $canonical);
    }

    public function testNullNameProducesNullFullName(): void
    {
        $canonical = PartyToPersonCanonical::toPersonCanonical([]);

        $this->assertSame(['fullName' => null], $canonical['name']);
    }

    public function testPersonTypeOverride(): void
    {
        $canonical = PartyToPersonCanonical::toPersonCanonical(
            ['name' => 'Jan Novák'],
            personType: 'person',
        );

        $this->assertSame('person', $canonical['personType']);
    }

    public function testWhitespaceInIdentifiersIsTrimmed(): void
    {
        $canonical = PartyToPersonCanonical::toPersonCanonical([
            'name'      => '  Acme s.r.o.  ',
            'companyId' => '  12345678  ',
        ]);

        $this->assertSame('Acme s.r.o.', $canonical['name']['fullName']);
        $this->assertSame('12345678', $canonical['companyId']);
    }
}
