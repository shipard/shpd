<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMatch;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryRecord;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistorySeedBuilder;

/** Prahy seed kandidátů IČO → štítek (D32). */
class BookingHistorySeedBuilderTest extends TestCase
{
    private function add(
        BookingHistorySeedBuilder $builder,
        ?string $companyId,
        ?string $tag,
        int $rows,
        int $docs,
    ): void {
        $record = new BookingHistoryRecord(
            companyId: $companyId,
            account: $tag !== null ? '518202' : '999999',
            itemCode: null,
            itemName: null,
            rowText: 'text',
            docCount: $docs,
            rowCount: $rows,
            totalAmount: null,
            firstDate: null,
            lastDate: null,
        );
        $match = $tag !== null
            ? new AccountTagMatch($tag, AccountTagMatch::KIND_EXACT)
            : new AccountTagMatch(null, AccountTagMatch::KIND_UNMAPPED);
        $builder->add($record, $match);
    }

    public function testDominantTagAboveThresholdsBecomesCandidate(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '26378191', 'it.internet', 90, 40);
        $this->add($builder, '26378191', 'office.supplies', 10, 3);

        $candidates = $builder->candidates();
        $this->assertCount(1, $candidates);
        $this->assertSame('26378191', $candidates[0]->companyId);
        $this->assertSame('it.internet', $candidates[0]->tag);
        $this->assertSame(90, $candidates[0]->rows);
        $this->assertSame(40, $candidates[0]->docs);
        $this->assertEqualsWithDelta(0.9, $candidates[0]->share, 0.0001);
        $this->assertEqualsWithDelta(1.0, $candidates[0]->coverage, 0.0001);
    }

    public function testShareBelowThresholdIsRejected(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '26378191', 'it.internet', 79, 40);
        $this->add($builder, '26378191', 'office.supplies', 21, 10);

        $this->assertSame([], $builder->candidates());
        $this->assertSame(1, $builder->skipped()['belowShare']);
    }

    public function testDocCountBelowThresholdIsRejected(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '26378191', 'it.internet', 100, 2);

        $this->assertSame([], $builder->candidates());
        $this->assertSame(1, $builder->skipped()['belowDocCount']);
    }

    public function testDocCountComesFromDominantTagNotWholeCompany(): void
    {
        // Celkem 6 dokladů, ale dominantní štítek jich má jen 2 → zamítnuto.
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '26378191', 'it.internet', 100, 2);
        $this->add($builder, '26378191', null, 1, 4);

        $this->assertSame([], $builder->candidates());
        $this->assertSame(1, $builder->skipped()['belowDocCount']);
    }

    public function testTieMeansNoCandidate(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '26378191', 'it.internet', 50, 20);
        $this->add($builder, '26378191', 'office.supplies', 50, 20);

        $this->assertSame([], $builder->candidates());
        $this->assertSame(1, $builder->skipped()['tie']);
    }

    public function testNullCompanyIdIsSkipped(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, null, 'it.internet', 100, 50);

        $this->assertSame([], $builder->candidates());
        $skipped = $builder->skipped();
        $this->assertSame(1, $skipped['noCompanyIdRecords']);
        $this->assertSame(0, $skipped['companies']);
    }

    public function testCompanyWithoutResolvedTagIsSkippedAndCoverageIsReported(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '11223344', null, 100, 50);
        $this->add($builder, '55667788', 'it.internet', 20, 5);
        $this->add($builder, '55667788', null, 80, 30);

        $candidates = $builder->candidates();
        $this->assertCount(1, $candidates, 'IČO bez rozřešeného štítku nemá kandidáta');
        $this->assertSame('55667788', $candidates[0]->companyId);
        // share nad rozřešenými řádky je 1.0, coverage ale odhalí, že reverz
        // pokryl jen pětinu historie dodavatele.
        $this->assertEqualsWithDelta(1.0, $candidates[0]->share, 0.0001);
        $this->assertEqualsWithDelta(0.2, $candidates[0]->coverage, 0.0001);
        $this->assertSame(100, $candidates[0]->totalRows);
        $this->assertSame(20, $candidates[0]->resolvedRows);
        $this->assertSame(1, $builder->skipped()['noResolvedTag']);
    }

    public function testCandidatesAreSortedByStrongestSupport(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '11111111', 'it.internet', 30, 10);
        $this->add($builder, '22222222', 'premises.rent', 300, 100);
        $this->add($builder, '33333333', 'services.postage', 100, 20);

        $this->assertSame(
            ['22222222', '33333333', '11111111'],
            array_map(static fn ($c): string => $c->companyId, $builder->candidates()),
        );
    }

    public function testCustomThresholds(): void
    {
        $builder = new BookingHistorySeedBuilder(minShare: 0.5, minDocCount: 1);
        $this->add($builder, '26378191', 'it.internet', 60, 1);
        $this->add($builder, '26378191', 'office.supplies', 40, 1);

        $this->assertCount(1, $builder->candidates());
    }
}
