<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\SupplierCodesResolver;

class SupplierCodesResolverTest extends TestCase
{
    // ── supplier matched + update flow ────────────────────────────────────

    public function testMatchedSupplierAndExistingMapping(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('economy_items_supplier_codes'),
                100, // personId
                'KONZ-001',
            )
            ->willReturn(new Row(['id' => 200]));

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->expects($this->once())
            ->method('resolve')
            ->with($this->anything(), PersonType::Company)
            ->willReturn(ResolveResult::matched(100, 'companyId'));

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            [
                'supplier'     => ['companyId' => '12345678'],
                'supplierCode' => 'KONZ-001',
                'supplierName' => 'Konzultace IT',
            ],
        ], itemId: 42);

        $this->assertCount(1, $out);
        $this->assertSame(0, $out[0]['index']);
        $this->assertSame('matched', $out[0]['status']);
        $this->assertSame(200, $out[0]['mappingId']);
        $this->assertSame('KONZ-001', $out[0]['supplierCode']);
        $this->assertSame('matched', $out[0]['supplier']['status']);
        $this->assertSame(100, $out[0]['supplier']['matchedId']);
    }

    public function testMatchedSupplierAndMissingMappingYieldsCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->method('resolve')->willReturn(ResolveResult::matched(100, 'companyId'));

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            [
                'supplier'     => ['companyId' => '12345678'],
                'supplierCode' => 'KONZ-001',
            ],
        ], itemId: 42);

        $this->assertSame('canCreate', $out[0]['status']);
        $this->assertArrayNotHasKey('mappingId', $out[0]);
    }

    // ── supplier matched + create flow (itemId = null) ────────────────────

    public function testItemIdNullSkipsMappingProbe(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())
            ->method('fetch'); // No mapping probe when itemId is null.

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->method('resolve')->willReturn(ResolveResult::matched(100, 'companyId'));

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            [
                'supplier'     => ['companyId' => '12345678'],
                'supplierCode' => 'KONZ-001',
            ],
        ], itemId: null);

        $this->assertSame('canCreate', $out[0]['status']);
        $this->assertSame(100, $out[0]['supplier']['matchedId']);
    }

    // ── supplier canCreate / ambiguous / notFound → skipped ───────────────

    public function testSupplierCanCreateYieldsSkippedWithIssue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->method('resolve')->willReturn(
            ResolveResult::canCreate(['full_name' => 'Beta s.r.o.', 'company_id' => '87654321']),
        );

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            [
                'supplier'     => ['companyId' => '87654321', 'name' => 'Beta s.r.o.'],
                'supplierCode' => 'BETA-X',
            ],
        ], itemId: 42);

        $this->assertSame('skipped', $out[0]['status']);
        $this->assertSame('supplier_unknown', $out[0]['issue']);
        $this->assertNull($out[0]['userAction']);
        $this->assertSame('canCreate', $out[0]['supplier']['status']);
    }

    public function testSupplierAmbiguousYieldsSkipped(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->method('resolve')->willReturn(
            ResolveResult::ambiguous([
                ['id' => 10, 'name' => 'Acme', 'companyId' => '11111111'],
                ['id' => 11, 'name' => 'Acme', 'companyId' => '22222222'],
            ]),
        );

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            [
                'supplier'     => ['name' => 'Acme'],
                'supplierCode' => 'X',
            ],
        ], itemId: 42);

        $this->assertSame('skipped', $out[0]['status']);
        $this->assertSame('supplier_unknown', $out[0]['issue']);
        $this->assertSame('ambiguous', $out[0]['supplier']['status']);
    }

    public function testSupplierNotFoundYieldsSkipped(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->method('resolve')->willReturn(ResolveResult::notFound());

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            [
                'supplier'     => [],
                'supplierCode' => 'X',
            ],
        ], itemId: 42);

        $this->assertSame('skipped', $out[0]['status']);
        $this->assertSame('supplier_unknown', $out[0]['issue']);
    }

    // ── multi-element payload ─────────────────────────────────────────────

    public function testMultiSupplierPayloadProducesPerIndexResults(): void
    {
        $db = $this->createMock(Connection::class);
        // One mapping probe per matched supplier (2 of 3 are matched).
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                new Row(['id' => 200]),  // first supplier mapping exists
                null,                    // second supplier mapping missing
            );

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->expects($this->exactly(3))
            ->method('resolve')
            ->willReturnOnConsecutiveCalls(
                ResolveResult::matched(100, 'companyId'),
                ResolveResult::matched(101, 'vatId'),
                ResolveResult::canCreate(['full_name' => 'New supplier']),
            );

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            ['supplier' => ['companyId' => '111'], 'supplierCode' => 'A'],
            ['supplier' => ['vatId' => 'CZ222'], 'supplierCode' => 'B'],
            ['supplier' => ['name' => 'New'], 'supplierCode' => 'C'],
        ], itemId: 42);

        $this->assertCount(3, $out);
        $this->assertSame('matched', $out[0]['status']);
        $this->assertSame(200, $out[0]['mappingId']);
        $this->assertSame('canCreate', $out[1]['status']);
        $this->assertSame('skipped', $out[2]['status']);
        $this->assertSame([0, 1, 2], array_column($out, 'index'));
    }

    // ── edge cases ────────────────────────────────────────────────────────

    public function testEmptyArrayProducesEmptyOutput(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->expects($this->never())->method('resolve');

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([], itemId: 42);

        $this->assertSame([], $out);
    }

    public function testEmptySupplierCodeIsTreatedAsSkipped(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch'); // no mapping probe

        $partyResolver = $this->createMock(PartyResolver::class);
        $partyResolver->method('resolve')->willReturn(ResolveResult::matched(100, 'companyId'));

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            ['supplier' => ['companyId' => '111'], 'supplierCode' => '   '],
        ], itemId: 42);

        $this->assertSame('skipped', $out[0]['status']);
    }

    public function testNonArrayElementIsSilentlySkipped(): void
    {
        $db = $this->createMock(Connection::class);
        $partyResolver = $this->createMock(PartyResolver::class);

        $partyResolver->expects($this->once())->method('resolve')->willReturn(ResolveResult::notFound());

        $out = (new SupplierCodesResolver($db, $partyResolver))->resolve([
            'not-an-array',
            ['supplier' => [], 'supplierCode' => 'X'],
        ], itemId: 42);

        $this->assertCount(1, $out);
        $this->assertSame(1, $out[0]['index'], 'index preserved from source array');
    }
}
