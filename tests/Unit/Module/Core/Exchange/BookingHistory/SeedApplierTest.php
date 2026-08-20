<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\SeedApplier;
use Shipard\Module\Core\Exchange\BookingHistory\SeedCandidate;

/**
 * Testable applier — Connection::query je final, executeSql se přepisuje
 * subclassingem (vzor TestableContentTagRuleCaptureHandler).
 */
class TestableSeedApplier extends SeedApplier
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->sqlCalls[] = $args;
    }
}

/** Zápis seed pravidel dle D32 — user/learned se nikdy nepřepíše. */
class SeedApplierTest extends TestCase
{
    /** @param array<string, array{tag: string, origin: string}> $existing */
    private function applier(array $existing = []): TestableSeedApplier
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (mixed ...$args) use ($existing): ?Row {
                // Poslední argument dotazu na pravidlo je IČO.
                $companyId = (string) end($args);
                return isset($existing[$companyId]) ? new Row($existing[$companyId]) : null;
            },
        );
        return new TestableSeedApplier($db);
    }

    private function candidate(string $companyId, string $tag): SeedCandidate
    {
        return new SeedCandidate(
            companyId: $companyId,
            tag: $tag,
            rows: 100,
            docs: 20,
            resolvedRows: 100,
            totalRows: 100,
            share: 1.0,
            coverage: 1.0,
        );
    }

    public function testInsertsNewRuleWithSeedOrigin(): void
    {
        $applier = $this->applier();
        $counts = $applier->apply([$this->candidate('26378191', 'it.internet')]);

        $this->assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0, 'same' => 0], $counts);
        $this->assertCount(1, $applier->sqlCalls);
        $sql = $applier->sqlCalls[0];
        $this->assertStringContainsString('INSERT INTO', (string) $sql[0]);
        $this->assertSame('core_exchange_tag_rules', $sql[1]);
        $this->assertSame('26378191', $sql[2]);
        $this->assertSame('it.internet', $sql[3]);
        $this->assertSame('seed', $sql[4]);
    }

    public function testExistingSeedRuleWithDifferentTagIsUpdated(): void
    {
        $applier = $this->applier(['26378191' => ['tag' => 'it.phone', 'origin' => 'seed']]);
        $counts = $applier->apply([$this->candidate('26378191', 'it.internet')]);

        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0, 'same' => 0], $counts);
        $sql = $applier->sqlCalls[0];
        $this->assertStringContainsString('UPDATE', (string) $sql[0]);
        $this->assertStringContainsString('[origin] = %s', (string) $sql[0]);
        $this->assertSame('it.internet', $sql[2]);
        $this->assertSame('seed', $sql[5], 'update se omezuje na seed pravidla');
    }

    public function testUserAndLearnedRulesAreNeverOverwritten(): void
    {
        foreach (['user', 'learned'] as $origin) {
            $applier = $this->applier(['26378191' => ['tag' => 'goods.stock', 'origin' => $origin]]);
            $counts = $applier->apply([$this->candidate('26378191', 'it.internet')]);

            $this->assertSame(1, $counts['skipped'], "origin {$origin} se nesmí přepsat");
            $this->assertSame([], $applier->sqlCalls, 'žádný zápis do DB');
        }
    }

    public function testSameTagIsNoOp(): void
    {
        $applier = $this->applier(['26378191' => ['tag' => 'it.internet', 'origin' => 'user']]);
        $counts = $applier->apply([$this->candidate('26378191', 'it.internet')]);

        $this->assertSame(1, $counts['same']);
        $this->assertSame([], $applier->sqlCalls);
    }

    public function testPlanDoesNotTouchDatabase(): void
    {
        $applier = $this->applier([
            '11111111' => ['tag' => 'it.phone', 'origin' => 'seed'],
            '22222222' => ['tag' => 'goods.stock', 'origin' => 'user'],
        ]);

        $plan = $applier->plan([
            $this->candidate('11111111', 'it.internet'),
            $this->candidate('22222222', 'it.internet'),
            $this->candidate('33333333', 'it.internet'),
        ]);

        $this->assertSame([], $applier->sqlCalls);
        $this->assertSame('update', $plan['11111111']['action']);
        $this->assertSame('it.phone', $plan['11111111']['existingTag']);
        $this->assertSame('skip', $plan['22222222']['action']);
        $this->assertSame('user', $plan['22222222']['existingOrigin']);
        $this->assertSame('insert', $plan['33333333']['action']);
    }

    public function testEmptyCandidateListWritesNothing(): void
    {
        $applier = $this->applier();
        $this->assertSame(
            ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'same' => 0],
            $applier->apply([]),
        );
        $this->assertSame([], $applier->sqlCalls);
    }
}
