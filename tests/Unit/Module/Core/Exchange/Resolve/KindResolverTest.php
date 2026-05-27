<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\KindResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

class KindResolverTest extends TestCase
{
    // ── Strategy 1 — system_code ───────────────────────────────────────────

    public function testSystemCodeMatchWins(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('system_code'),
                'service',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 5]));

        $r = (new KindResolver($db))->resolve([
            'code' => 'service',
            'name' => 'Should not matter, code wins',
            'itemType' => 1,
        ]);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(5, $r->matchedId);
        $this->assertSame('system_code', $r->matchedBy);
    }

    public function testSystemCodeMissFallsThroughToName(): void
    {
        $db = $this->createMock(Connection::class);
        // Strategy 1 (system_code) uses fetch; strategy 2 (name) uses fetchAll.
        $db->expects($this->once())->method('fetch')->willReturn(null);
        $db->expects($this->once())
            ->method('fetchAll')
            ->willReturn([new Row(['id' => 7, 'name' => 'Konzultace', 'item_type' => 0, 'system_code' => null])]);

        $r = (new KindResolver($db))->resolve([
            'code' => 'nonexistent',
            'name' => 'Konzultace',
        ]);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(7, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }

    // ── Strategy 2 — name ─────────────────────────────────────────────────

    public function testNameSingleMatch(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->stringContains('[name] = %s'),
                'Konzultace IT',
                10, 40, 80,
                5,
            )
            ->willReturn([new Row(['id' => 8, 'name' => 'Konzultace IT', 'item_type' => 0, 'system_code' => null])]);

        $r = (new KindResolver($db))->resolve([
            'name' => 'Konzultace IT',
        ]);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(8, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }

    public function testNameMultiMatchYieldsAmbiguous(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 10, 'name' => 'Konzultace', 'item_type' => 0, 'system_code' => null]),
            new Row(['id' => 11, 'name' => 'Konzultace', 'item_type' => 1, 'system_code' => null]),
        ]);

        $r = (new KindResolver($db))->resolve([
            'name' => 'Konzultace',
        ]);

        $this->assertSame(ResolveStatus::Ambiguous, $r->status);
        $this->assertCount(2, $r->candidates);
        $this->assertSame(10, $r->candidates[0]['id']);
        $this->assertSame(11, $r->candidates[1]['id']);
    }

    public function testNameWhitespaceIsTrimmed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->stringContains('[name] = %s'),
                'Konzultace', // trimmed
                10, 40, 80,
                5,
            )
            ->willReturn([new Row(['id' => 12, 'name' => 'Konzultace', 'item_type' => 0, 'system_code' => null])]);

        $r = (new KindResolver($db))->resolve([
            'name' => '   Konzultace   ',
        ]);

        $this->assertSame(12, $r->matchedId);
    }

    // ── Strategy 3 — itemType fallback ────────────────────────────────────

    public function testItemTypeFallbackWhenOnlyItemTypeProvided(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('system_code'),
                'stock', // itemType=1 → stock
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 20]));

        $r = (new KindResolver($db))->resolve([
            'itemType' => 1,
        ]);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(20, $r->matchedId);
        $this->assertSame('itemTypeFallback', $r->matchedBy);
    }

    public function testNameMissYieldsCanCreateBeforeItemTypeFallback(): void
    {
        $db = $this->createMock(Connection::class);
        // Only strategy 1 (system_code='svc-typo') probe runs and misses.
        // No fallback probe — strategy 3 (canCreate from name) wins because
        // the caller's explicit name is a stronger signal than itemType.
        $db->expects($this->once())->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]); // strategy 2 miss

        $r = (new KindResolver($db))->resolve([
            'code'     => 'svc-typo',
            'name'     => 'Konzultace',
            'itemType' => 0,
        ]);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('Konzultace', $r->createPayload['name']);
        $this->assertSame(0, $r->createPayload['item_type']);
    }

    public function testItemTypeFallbackMapsAllFourValues(): void
    {
        $expected = [0 => 'service', 1 => 'stock', 2 => 'accounting', 3 => 'other'];
        foreach ($expected as $type => $systemCode) {
            $db = $this->createMock(Connection::class);
            $db->expects($this->once())
                ->method('fetch')
                ->with(
                    $this->stringContains('system_code'),
                    $systemCode,
                    10, 40, 80,
                )
                ->willReturn(new Row(['id' => 30 + $type]));

            $r = (new KindResolver($db))->resolve(['itemType' => $type]);
            $this->assertSame(ResolveStatus::Matched, $r->status, "itemType={$type} should resolve");
            $this->assertSame('itemTypeFallback', $r->matchedBy);
            $this->assertSame(30 + $type, $r->matchedId);
        }
    }

    public function testUnknownItemTypeIsIgnored(): void
    {
        $db = $this->createMock(Connection::class);
        // itemType=7 is not in the fallback table → no probe at all → notFound.
        $db->expects($this->never())->method('fetch');
        $db->expects($this->never())->method('fetchAll');

        $r = (new KindResolver($db))->resolve(['itemType' => 7]);

        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testNameMissWithItemTypeYieldsCanCreateCarryingItemType(): void
    {
        $db = $this->createMock(Connection::class);
        // No code → no fetch probe. name miss → strategy 3 canCreate
        // (fires before itemTypeFallback because name is the stronger
        // signal). No fallback probe needed.
        $db->expects($this->never())->method('fetch');
        $db->method('fetchAll')->willReturn([]);

        $r = (new KindResolver($db))->resolve([
            'name' => 'Účetní operace',
            'itemType' => 2,
        ]);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('Účetní operace', $r->createPayload['name']);
        $this->assertSame(2, $r->createPayload['item_type']);
    }

    // ── Strategy 4 — canCreate ────────────────────────────────────────────

    public function testNameOnlyNoMatchYieldsCanCreateWithItemTypeOther(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([]);

        $r = (new KindResolver($db))->resolve([
            'name' => 'Nový druh',
        ]);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('Nový druh', $r->createPayload['name']);
        $this->assertSame(3, $r->createPayload['item_type'], 'item_type defaults to 3 (Other) when not hinted');
        $this->assertSame(40, $r->createPayload['docState']);
        $this->assertSame(2, $r->createPayload['docStateMain']);
    }

    public function testCanCreatePayloadCarriesProvidedItemType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);

        $r = (new KindResolver($db))->resolve([
            'name' => 'Hardware notebooky',
            'itemType' => 1,
        ]);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame(1, $r->createPayload['item_type']);
    }

    // ── No hint at all ────────────────────────────────────────────────────

    public function testEmptyKindYieldsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');
        $db->expects($this->never())->method('fetchAll');

        $r = (new KindResolver($db))->resolve([]);

        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testWhitespaceOnlyCodeAndNameAreIgnored(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');
        $db->expects($this->never())->method('fetchAll');

        $r = (new KindResolver($db))->resolve([
            'code' => '   ',
            'name' => '',
        ]);

        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    // ── docState filter ──────────────────────────────────────────────────

    public function testFiltersArchivedAndDeletedKinds(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('docState'),
                'service',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 5]));

        (new KindResolver($db))->resolve(['code' => 'service']);
        // Assertion is in the mock expectation — docState IN (10,40,80) only.
    }
}
