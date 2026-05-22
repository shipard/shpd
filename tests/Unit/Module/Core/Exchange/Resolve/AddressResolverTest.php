<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\AddressResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

class AddressResolverTest extends TestCase
{
    // ── Match-key priority ─────────────────────────────────────────────────

    public function testPlaceRegIdMatchWinsAndSetsAuthoritativeRefresh(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('place_reg_type'),
                42,
                'ICP',
                '1234567890',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 100]));

        $r = (new AddressResolver($db))->resolve([
            'addressType'  => 3,
            'placeRegType' => 'ICP',
            'placeRegId'   => '1234567890',
            'registryCode' => '21794160',  // would also match if we got here
        ], personId: 42);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(100, $r->matchedId);
        $this->assertSame('placeRegId', $r->matchedBy);
        $this->assertTrue($r->authoritativeRefresh, 'placeRegId match must flag authoritativeRefresh');
    }

    public function testRegistryCodeMatchUsedWhenPlaceRegMisses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                       // placeReg probe miss
                new Row(['id' => 200]),     // registry_code hit
            );

        $r = (new AddressResolver($db))->resolve([
            'addressType'  => 1,
            'placeRegType' => 'ICP',
            'placeRegId'   => '1234567890',
            'registryCode' => '21794160',
        ], personId: 42);

        $this->assertSame(200, $r->matchedId);
        $this->assertSame('registryCode', $r->matchedBy);
        $this->assertFalse($r->authoritativeRefresh);
    }

    public function testDisplayLineFallbackForUnstandardizedAddresses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('display_line'),
                42,
                2,
                'Foo street 12, 1010 Vienna',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 300]));

        $r = (new AddressResolver($db))->resolve([
            'addressType'    => 2,
            'isStandardized' => false,
            'displayLine'    => 'Foo street 12, 1010 Vienna',
            'country'        => 'at',
        ], personId: 42);

        $this->assertSame(300, $r->matchedId);
        $this->assertSame('displayLine', $r->matchedBy);
        $this->assertFalse($r->authoritativeRefresh);
    }

    public function testStandardizedAddressDoesNotFallBackToDisplayLine(): void
    {
        $db = $this->createMock(Connection::class);
        // No probe even attempted — isStandardized=true skips displayLine
        // fallback. With only the displayLine path matchable, we expect
        // canCreate (no probes called at all).
        $db->expects($this->never())->method('fetch');

        $r = (new AddressResolver($db))->resolve([
            'addressType'    => 1,
            'isStandardized' => true,
            'displayLine'    => 'Anything',
        ], personId: 42);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
    }

    public function testNoMatchYieldsCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        // Two probes attempted (placeReg + registryCode), both miss; no
        // displayLine because isStandardized=true.
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(null, null);

        $r = (new AddressResolver($db))->resolve([
            'addressType'    => 3,
            'placeRegType'   => 'ICP',
            'placeRegId'     => '9999999999',
            'registryCode'   => '99999999',
            'isStandardized' => true,
            'street'         => 'Hlavní',
            'city'           => 'Praha',
        ], personId: 42);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $payload = $r->createPayload;
        $this->assertSame(42, $payload['person']);
        $this->assertSame(3, $payload['address_type']);
        $this->assertSame('ICP', $payload['place_reg_type']);
        $this->assertSame('9999999999', $payload['place_reg_id']);
        $this->assertSame('Hlavní', $payload['street']);
        $this->assertSame('Praha', $payload['city']);
        $this->assertSame(1, $payload['is_standardized']);
    }

    // ── personId = null (header is canCreate) ─────────────────────────────

    public function testNullPersonIdShortCircuitsToCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $r = (new AddressResolver($db))->resolve([
            'addressType'  => 1,
            'placeRegType' => 'ICP',
            'placeRegId'   => 'X',
        ], personId: null);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $payload = $r->createPayload;
        $this->assertNull($payload['person']);
        $this->assertSame(1, $payload['address_type']);
    }

    // ── divisionCode lookup ───────────────────────────────────────────────

    public function testDivisionCodeLookupReturnsIdWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('world_divisions'),
                '554782',
            )
            ->willReturn(new Row(['id' => 777]));

        $this->assertSame(777, (new AddressResolver($db))->lookupDivisionId('554782'));
    }

    public function testDivisionCodeLookupDoesNotFilterByDocState(): void
    {
        // world_divisions is reference data without a docState column;
        // the lookup must NOT pass the historic ACTIVE_STATES tuple
        // (otherwise it crashes against the real schema).
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (...$args) use (&$captured): ?Row {
                $captured = $args;
                return null;
            },
        );

        (new AddressResolver($db))->lookupDivisionId('554782');

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringNotContainsString('docState', $sql, 'world_divisions has no docState column');
    }

    public function testDivisionCodeLookupReturnsNullForUnknownCode(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $this->assertNull((new AddressResolver($db))->lookupDivisionId('XXXXX'));
    }

    public function testDivisionCodeLookupReturnsNullForEmptyOrNullInput(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $r = new AddressResolver($db);
        $this->assertNull($r->lookupDivisionId(null));
        $this->assertNull($r->lookupDivisionId(''));
        $this->assertNull($r->lookupDivisionId('  '));
    }

    public function testCreatePayloadResolvesDivisionFromCode(): void
    {
        $db = $this->createMock(Connection::class);
        // Sequence: registryCode probe (miss) → world_divisions lookup
        // inside buildCreatePayload (hit). No placeReg probe — placeRegId
        // is not supplied.
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                       // registryCode miss
                new Row(['id' => 999]),     // division lookup hit
            );

        $r = (new AddressResolver($db))->resolve([
            'addressType'   => 1,
            'registryCode'  => '21794160',
            'divisionCode'  => '554782',
            'street'        => 'Hlavní',
        ], personId: 42);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame(999, $r->createPayload['division']);
    }

    public function testCreatePayloadDivisionNullWhenLookupMisses(): void
    {
        $db = $this->createMock(Connection::class);
        // No match-key probes possible (no placeRegId, no registryCode,
        // isStandardized=true blocks displayLine) — straight to
        // canCreate. buildCreatePayload then attempts the division lookup
        // which misses.
        $db->expects($this->once())
            ->method('fetch')
            ->willReturn(null);

        $r = (new AddressResolver($db))->resolve([
            'addressType'    => 1,
            'isStandardized' => true,
            'divisionCode'   => 'UNKNOWN',
            'street'         => 'Hlavní',
        ], personId: 42);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertNull($r->createPayload['division']);
    }

    // ── Payload shape ─────────────────────────────────────────────────────

    public function testCreatePayloadLowercasesCountry(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $r = (new AddressResolver($db))->resolve([
            'addressType' => 1,
            'country'     => 'CZ',  // canonical uses uppercase here too sometimes
            'isStandardized' => true,
        ], personId: 42);

        $this->assertSame('cz', $r->createPayload['country']);
    }

    public function testCreatePayloadDefaultsOrderPosToZero(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $r = (new AddressResolver($db))->resolve([
            'addressType'    => 1,
            'isStandardized' => true,
        ], personId: 42);

        $this->assertSame(0, $r->createPayload['order_pos']);
    }
}
