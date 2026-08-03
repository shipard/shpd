<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Registry\SearchResultRow;

class SearchResultRowTest extends TestCase
{
    public function testConstructorRejectsEmptyCountry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchResultRow('', '12345678', 'X', null, true, null, null, null);
    }

    public function testConstructorRejectsEmptyCompanyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchResultRow('cz', '', 'X', null, true, null, null, null);
    }

    public function testConstructorRejectsEmptyFullName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchResultRow('cz', '12345678', '', null, true, null, null, null);
    }

    public function testFromRegistryResponseHappyPath(): void
    {
        $row = SearchResultRow::fromRegistryResponse([
            'country'            => 'CZ',
            'oid'                => '12345678',
            'fullName'           => 'Zkušební firma s.r.o.',
            'vatID'              => 'CZ12345678',
            'valid'              => 1,
            'validFrom'          => '2000-01-01',
            'validTo'            => null,
            'primaryAddressText' => 'Zkušební 1, 10000',
        ]);

        $this->assertNotNull($row);
        $this->assertSame('cz', $row->country);
        $this->assertSame('12345678', $row->companyId);
        $this->assertSame('Zkušební firma s.r.o.', $row->fullName);
        $this->assertSame('CZ12345678', $row->vatId);
        $this->assertTrue($row->isValid);
        $this->assertSame('2000-01-01', $row->validFrom);
        $this->assertNull($row->validTo);
    }

    public function testFromRegistryResponseReturnsNullOnMissingRequiredFields(): void
    {
        $this->assertNull(SearchResultRow::fromRegistryResponse(['oid' => '1', 'fullName' => 'X']));
        $this->assertNull(SearchResultRow::fromRegistryResponse(['country' => 'cz', 'fullName' => 'X']));
        $this->assertNull(SearchResultRow::fromRegistryResponse(['country' => 'cz', 'oid' => '1']));
        $this->assertNull(SearchResultRow::fromRegistryResponse([]));
    }

    public function testFromRegistryResponseNormalizesEmptyStringsToNull(): void
    {
        $row = SearchResultRow::fromRegistryResponse([
            'country'            => 'cz',
            'oid'                => '12345678',
            'fullName'           => 'X',
            'vatID'              => '   ',     // whitespace → null
            'valid'              => 0,
            'validFrom'          => '',        // empty → null
            'validTo'            => '2020-01-01',
            'primaryAddressText' => null,
        ]);

        $this->assertNotNull($row);
        $this->assertNull($row->vatId);
        $this->assertNull($row->validFrom);
        $this->assertSame('2020-01-01', $row->validTo);
        $this->assertNull($row->primaryAddressText);
        $this->assertFalse($row->isValid);
    }

    public function testToArrayRoundTripsAllFields(): void
    {
        $row = new SearchResultRow('cz', '12345678', 'X', 'CZ12345678', true, '2020-01-01', null, 'Praha');
        $this->assertSame([
            'country'            => 'cz',
            'companyId'          => '12345678',
            'fullName'           => 'X',
            'vatId'              => 'CZ12345678',
            'isValid'            => true,
            'validFrom'          => '2020-01-01',
            'validTo'            => null,
            'primaryAddressText' => 'Praha',
        ], $row->toArray());
    }
}
