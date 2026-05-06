<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

class OwnCompanyResolverTest extends TestCase
{
    public function testGetOwnPersonIdReturnsIdWhenPresent(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 42]);

        $resolver = new OwnCompanyResolver($db);
        $this->assertSame(42, $resolver->getOwnPersonId());
    }

    public function testGetOwnPersonIdReturnsNullWhenAbsent(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $resolver = new OwnCompanyResolver($db);
        $this->assertNull($resolver->getOwnPersonId());
    }

    public function testGetOwnPersonDataReturnsNullWhenNoOwnPerson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $resolver = new OwnCompanyResolver($db);
        $this->assertNull($resolver->getOwnPersonData());
    }

    public function testGetOwnPersonDataReturnsRowWhenFound(): void
    {
        $db = $this->createMock(DataSourceConnection::class);

        $callCount = 0;
        $db->method('fetchRow')->willReturnCallback(
            function () use (&$callCount): ?array {
                $callCount++;
                if ($callCount === 1) {
                    return ['id' => 7];
                }
                return ['id' => 7, 'name' => 'Vlastní firma s.r.o.', 'is_own' => 1];
            }
        );

        $resolver = new OwnCompanyResolver($db);
        $data = $resolver->getOwnPersonData();
        $this->assertNotNull($data);
        $this->assertSame(7, $data['id']);
        $this->assertSame('Vlastní firma s.r.o.', $data['name']);
    }

    public function testGetOwnHeadquartersAddressReturnsNullWhenNoOwnPerson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $resolver = new OwnCompanyResolver($db);
        $this->assertNull($resolver->getOwnHeadquartersAddress());
    }

    public function testGetOwnHeadquartersAddressReturnsAddressWhenFound(): void
    {
        $db = $this->createMock(DataSourceConnection::class);

        $callCount = 0;
        $db->method('fetchRow')->willReturnCallback(
            function () use (&$callCount): ?array {
                $callCount++;
                if ($callCount === 1) {
                    return ['id' => 7];
                }
                return [
                    'id' => 100,
                    'person' => 7,
                    'address_type' => 1,
                    'street' => 'Hlavní 1',
                    'city' => 'Praha',
                ];
            }
        );

        $resolver = new OwnCompanyResolver($db);
        $address = $resolver->getOwnHeadquartersAddress();
        $this->assertNotNull($address);
        $this->assertSame(1, $address['address_type']);
        $this->assertSame('Praha', $address['city']);
    }
}
