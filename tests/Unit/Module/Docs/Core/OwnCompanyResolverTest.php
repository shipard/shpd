<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

class OwnCompanyResolverTest extends TestCase
{
    public function testGetOwnPersonIdReturnsIdWhenPresent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 42]));

        $resolver = new OwnCompanyResolver($db);
        $this->assertSame(42, $resolver->getOwnPersonId());
    }

    public function testGetOwnPersonIdReturnsNullWhenAbsent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $resolver = new OwnCompanyResolver($db);
        $this->assertNull($resolver->getOwnPersonId());
    }

    public function testGetOwnPersonDataReturnsNullWhenNoOwnPerson(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $resolver = new OwnCompanyResolver($db);
        $this->assertNull($resolver->getOwnPersonData());
    }

    public function testGetOwnPersonDataReturnsRowArrayWhenFound(): void
    {
        $db = $this->createMock(Connection::class);

        $callCount = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$callCount): ?Row {
                $callCount++;
                if ($callCount === 1) {
                    return new Row(['id' => 7]);
                }
                return new Row(['id' => 7, 'full_name' => 'Vlastní firma s.r.o.', 'is_own' => 1]);
            }
        );

        $resolver = new OwnCompanyResolver($db);
        $data = $resolver->getOwnPersonData();
        $this->assertNotNull($data);
        $this->assertSame(7, $data['id']);
        $this->assertSame('Vlastní firma s.r.o.', $data['full_name']);
    }

    public function testGetOwnHeadquartersAddressReturnsNullWhenNoOwnPerson(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $resolver = new OwnCompanyResolver($db);
        $this->assertNull($resolver->getOwnHeadquartersAddress());
    }

    public function testGetOwnHeadquartersAddressReturnsAddressWhenFound(): void
    {
        $db = $this->createMock(Connection::class);

        $callCount = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$callCount): ?Row {
                $callCount++;
                if ($callCount === 1) {
                    return new Row(['id' => 7]);
                }
                return new Row([
                    'id' => 100,
                    'person' => 7,
                    'address_type' => 1,
                    'street' => 'Hlavní 1',
                    'city' => 'Praha',
                ]);
            }
        );

        $resolver = new OwnCompanyResolver($db);
        $address = $resolver->getOwnHeadquartersAddress();
        $this->assertNotNull($address);
        $this->assertSame(1, $address['address_type']);
        $this->assertSame('Praha', $address['city']);
    }
}
