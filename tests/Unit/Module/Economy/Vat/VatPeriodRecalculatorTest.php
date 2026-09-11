<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\ReportPeriodLookup;
use Shipard\Module\Economy\Vat\VatPeriodRecalculator;

final class RecalcTestLookup implements ReportPeriodLookup
{
    /** @param list<array{0: int, 1: int, 2: string, 3: string, 4: string}> $instances */
    public function __construct(private readonly array $instances) {}

    public function covering(int $registrationId, string $type, string $date): ?array
    {
        foreach ($this->instances as [$id, $reg, $t, $begin, $end]) {
            if ($reg === $registrationId && $t === $type && $begin <= $date && $end >= $date) {
                return ['id' => $id, 'date_begin' => $begin, 'date_end' => $end];
            }
        }
        return null;
    }
}

final class TestableVatPeriodRecalculator extends VatPeriodRecalculator
{
    /** @var array<int, array<string, mixed>> */
    public array $instanceRows = [];
    /** @var list<array<string, mixed>> */
    public array $heads = [];
    /** @var array<int, true> */
    public array $lockedIds = [];
    public RecalcTestLookup $lookupValue;
    /** @var list<array{id: int, updates: array<string, ?int>}> */
    public array $updates = [];

    protected function loadInstance(int $instanceId): ?array
    {
        return $this->instanceRows[$instanceId] ?? null;
    }

    protected function loadHeads(int $regId, string $column, int $instanceId, string $begin, string $end): array
    {
        return $this->heads;
    }

    protected function loadRecapCodes(array $headIds): array
    {
        return [];
    }

    protected function loadInstances(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->instanceRows[$id])) {
                $r = $this->instanceRows[$id];
                $out[$id] = ['type' => $r['report_type'], 'date_begin' => $r['date_begin'], 'date_end' => $r['date_end']];
            }
        }
        return $out;
    }

    protected function lockedInstanceIds(array $ids): array
    {
        return array_intersect_key($this->lockedIds, array_flip($ids));
    }

    protected function lookup(): ReportPeriodLookup
    {
        return $this->lookupValue;
    }

    protected function updateHead(int $headId, array $updates): void
    {
        $this->updates[] = ['id' => $headId, 'updates' => $updates];
    }
}

/**
 * VatPeriodRecalculator × zámek (#55 D26): přepočet, který by přepsal
 * ukazatel dokladu ze zamčené nebo do zamčené instance, spadne
 * DomainException a nic nezapíše.
 *
 * Scénář: přiznání 200 (reg 5) se zkrátilo na leden–únor; doklad FV-1
 * s DUZP 15. 3. na ně dosud míří (nekonzistentní) a patří do 201 (březen).
 * FV-2 s DUZP 15. 1. je konzistentní.
 */
final class VatPeriodRecalculatorTest extends TestCase
{
    private function recalculator(): TestableVatPeriodRecalculator
    {
        $db = (new \ReflectionClass(DataSourceConnection::class))->newInstanceWithoutConstructor();
        $r = new TestableVatPeriodRecalculator($db, null);
        $r->instanceRows = [
            200 => ['id' => 200, 'vat_registration' => 5, 'report_type' => 'return', 'date_begin' => '2026-01-01', 'date_end' => '2026-02-28'],
            201 => ['id' => 201, 'vat_registration' => 5, 'report_type' => 'return', 'date_begin' => '2026-03-01', 'date_end' => '2026-03-31'],
        ];
        $r->lookupValue = new RecalcTestLookup([
            [200, 5, 'return', '2026-01-01', '2026-02-28'],
            [201, 5, 'return', '2026-03-01', '2026-03-31'],
        ]);
        $r->heads = [
            ['id' => 1, 'doc_number' => 'FV-1', 'vat_registration' => 5, 'vat_duzp' => '2026-03-15', 'vat_dppd' => null, 'vat_period' => 200, 'cs_period' => null, 'rs_period' => null],
            ['id' => 2, 'doc_number' => 'FV-2', 'vat_registration' => 5, 'vat_duzp' => '2026-01-15', 'vat_dppd' => null, 'vat_period' => 200, 'cs_period' => null, 'rs_period' => null],
        ];
        return $r;
    }

    public function testInconsistentPointerIsMovedWhenNothingIsLocked(): void
    {
        $r = $this->recalculator();

        $this->assertSame(1, $r->recomputeForInstance(200));
        $this->assertSame([['id' => 1, 'updates' => ['vat_period' => 201]]], $r->updates);
    }

    public function testMoveIntoLockedInstanceThrowsAndWritesNothing(): void
    {
        $r = $this->recalculator();
        $r->lockedIds = [201 => true];

        try {
            $r->recomputeForInstance(200);
            $this->fail('expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('FV-1', $e->getMessage());
            $this->assertStringContainsString('(1)', $e->getMessage());
            $this->assertStringNotContainsString('FV-2', $e->getMessage());
        }
        $this->assertSame([], $r->updates);
    }

    public function testMoveOutOfLockedInstanceThrows(): void
    {
        $r = $this->recalculator();
        $r->lockedIds = [200 => true];   // zdrojová instance zamčená

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('FV-1');
        $r->recomputeForInstance(200);
    }

    public function testLockedInstanceWithoutPendingChangesIsHarmless(): void
    {
        $r = $this->recalculator();
        $r->lockedIds = [200 => true];
        $r->heads = [$r->heads[1]];   // jen konzistentní FV-2

        $this->assertSame(0, $r->recomputeForInstance(200));
        $this->assertSame([], $r->updates);
    }

    public function testUnknownInstanceIsNoop(): void
    {
        $r = $this->recalculator();
        $this->assertSame(0, $r->recomputeForInstance(999));
    }

    public function testLockMessageListsAtMostFiveDocuments(): void
    {
        $message = VatPeriodRecalculator::lockMessage(['A', 'B', 'C', 'D', 'E', 'F', 'G']);

        $this->assertStringContainsString('(7): A, B, C, D, E a 2 dalších.', $message);
        $this->assertStringNotContainsString('F', explode('):', $message)[1]);
    }
}
