<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\FiscalMonthLockProvider;

final class TestableFiscalMonthLockProvider extends FiscalMonthLockProvider
{
    /** @var array<string, int> ISO datum → id měsíce */
    public array $monthsByDate = [];
    /** @var array<int, array{calendar_year: int, calendar_month: int}> zamčené měsíce */
    public array $locked = [];
    public int $lookups = 0;
    /** @var list<list<int>> */
    public array $lockedQueries = [];

    public function withDb(): self
    {
        $this->db = (new \ReflectionClass(\Dibi\Connection::class))->newInstanceWithoutConstructor();
        return $this;
    }

    protected function monthIdForDate(string $date): ?int
    {
        $this->lookups++;
        return $this->monthsByDate[$date] ?? null;
    }

    protected function lockedMonths(array $ids): array
    {
        $this->lockedQueries[] = $ids;
        return array_intersect_key($this->locked, array_flip($ids));
    }
}

/**
 * FiscalMonthLockProvider (#55 D27): původní i nový měsíc dokladu, bez
 * ohledu na obsah a stav. Měsíce: 301 = 2026/01 (zamčený), 302 = 2026/02.
 */
final class FiscalMonthLockProviderTest extends TestCase
{
    private const TABLE = 'docs_core_heads';

    private function provider(): TestableFiscalMonthLockProvider
    {
        $p = (new TestableFiscalMonthLockProvider())->withDb();
        $p->monthsByDate = ['2026-01-15' => 301, '2026-01-31' => 301, '2026-02-10' => 302];
        $p->locked = [301 => ['calendar_year' => 2026, 'calendar_month' => 1]];
        return $p;
    }

    public function testStoredDocumentInLockedMonthIsLockedRegardlessOfContent(): void
    {
        $p = $this->provider();
        // Bezdaňový koncept — obsah ani stav nehrají roli.
        $row = ['id' => 1, 'docState' => 10, 'vat_mode' => 0, 'accounting_date' => '2026-01-15', 'fiscal_month' => 301];

        $reasons = $p->lockReasons(self::TABLE, $row, $row);

        $this->assertCount(1, $reasons);
        $this->assertSame('fiscal_month', $reasons[0]->source);
        $this->assertSame('Fiskální měsíc 2026/01 je uzamčený', $reasons[0]->title);
        $this->assertSame(314, $reasons[0]->subjectTableId);
        $this->assertSame(301, $reasons[0]->subjectRowId);
        $this->assertSame(['year' => 2026, 'month' => 1], $reasons[0]->params);
        // Nezměněné účetní datum → bez lookupu.
        $this->assertSame(0, $p->lookups);
    }

    public function testMovingDocumentIntoLockedMonthIsBlocked(): void
    {
        $p = $this->provider();
        $original = ['id' => 2, 'accounting_date' => '2026-02-10', 'fiscal_month' => 302];
        $data = $original;
        $data['accounting_date'] = '2026-01-31';

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        $this->assertCount(1, $reasons);
        $this->assertSame(301, $reasons[0]->subjectRowId);
        $this->assertSame(1, $p->lookups);
        $this->assertEqualsCanonicalizing([302, 301], $p->lockedQueries[0]);
    }

    public function testMovingDocumentOutOfLockedMonthIsBlocked(): void
    {
        $p = $this->provider();
        $original = ['id' => 3, 'accounting_date' => '2026-01-15', 'fiscal_month' => 301];
        $data = $original;
        $data['accounting_date'] = '2026-02-10';

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        $this->assertCount(1, $reasons);
        $this->assertSame(301, $reasons[0]->subjectRowId);
    }

    public function testNewDocumentWithDateInLockedMonthIsBlocked(): void
    {
        $p = $this->provider();
        $data = ['accounting_date' => '2026-01-15', 'docState' => 10];

        $this->assertCount(1, $p->lockReasons(self::TABLE, $data, null));
    }

    public function testDocumentInFreeMonthPasses(): void
    {
        $p = $this->provider();
        $data = ['accounting_date' => '2026-02-10'];

        $this->assertSame([], $p->lockReasons(self::TABLE, $data, null));
    }

    public function testWithoutAccountingDateNewSideIsSilent(): void
    {
        $p = $this->provider();
        $this->assertSame([], $p->lockReasons(self::TABLE, ['accounting_date' => null], null));
        $this->assertSame(0, $p->lookups);
        $this->assertSame([], $p->lockedQueries);

        // …ale původní strana platí i bez nového data.
        $original = ['id' => 4, 'accounting_date' => '2026-01-15', 'fiscal_month' => 301];
        $this->assertCount(1, $p->lockReasons(self::TABLE, ['id' => 4, 'accounting_date' => ''], $original));
    }

    public function testDateTimeAccountingDateIsNormalized(): void
    {
        $p = $this->provider();
        $original = ['id' => 5, 'accounting_date' => new \DateTimeImmutable('2026-01-15 00:00:00'), 'fiscal_month' => 301];
        $data = ['id' => 5, 'accounting_date' => '2026-01-15'];

        $this->assertCount(1, $p->lockReasons(self::TABLE, $data, $original));
        $this->assertSame(0, $p->lookups);
    }

    public function testWithoutDbProviderIsSilent(): void
    {
        $p = new TestableFiscalMonthLockProvider();
        $p->locked = [301 => ['calendar_year' => 2026, 'calendar_month' => 1]];
        $row = ['id' => 1, 'accounting_date' => '2026-01-15', 'fiscal_month' => 301];

        $this->assertSame([], $p->lockReasons(self::TABLE, $row, $row));
    }
}
