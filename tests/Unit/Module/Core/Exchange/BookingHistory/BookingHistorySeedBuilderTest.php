<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMatch;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryRecord;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistorySeedBuilder;
use Shipard\Module\Core\Exchange\BookingHistory\SeedCandidate;

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

    /**
     * Dominance 100 % na pětině historie dodavatele = pravidlo z malého
     * výseku. Do D37 takový kandidát prošel; od D37 ho zastaví práh
     * pokrytí, ale v náhledu zůstane vidět.
     */
    public function testLowCoverageCandidateIsRejectedButStaysInPreview(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '11223344', null, 100, 50);
        $this->add($builder, '55667788', 'it.internet', 20, 5);
        $this->add($builder, '55667788', null, 80, 30);

        $this->assertSame([], $builder->candidates(), 'pod prahem pokrytí se nezakládá');

        $preview = $builder->previewCandidates();
        $this->assertCount(1, $preview, 'IČO bez rozřešeného štítku nemá kandidáta ani v náhledu');
        $this->assertSame('55667788', $preview[0]->companyId);
        $this->assertSame(SeedCandidate::REJECTED_COVERAGE, $preview[0]->rejectedBy);
        $this->assertFalse($preview[0]->isAccepted());
        $this->assertEqualsWithDelta(1.0, $preview[0]->share, 0.0001);
        $this->assertEqualsWithDelta(0.2, $preview[0]->coverage, 0.0001);
        $this->assertSame(100, $preview[0]->totalRows);
        $this->assertSame(20, $preview[0]->resolvedRows);

        $skipped = $builder->skipped();
        $this->assertSame(1, $skipped['noResolvedTag']);
        $this->assertSame(1, $skipped['belowCoverage']);
    }

    /** Pilotní čísla: share 1.0, docs 21, coverage 0.27 → neprojde. */
    public function testPilotShapedCandidateBelowCoverage(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '26378191', 'people.catering', 27, 21);
        $this->add($builder, '26378191', null, 73, 40);

        $this->assertSame([], $builder->candidates());
        $preview = $builder->previewCandidates();
        $this->assertEqualsWithDelta(0.27, $preview[0]->coverage, 0.0001);
        $this->assertSame(SeedCandidate::REJECTED_COVERAGE, $preview[0]->rejectedBy);
    }

    public function testCoverageAboveThresholdPasses(): void
    {
        $builder = new BookingHistorySeedBuilder();
        $this->add($builder, '26378191', 'people.catering', 60, 21);
        $this->add($builder, '26378191', null, 40, 20);

        $candidates = $builder->candidates();
        $this->assertCount(1, $candidates);
        $this->assertEqualsWithDelta(0.6, $candidates[0]->coverage, 0.0001);
        $this->assertTrue($candidates[0]->isAccepted());
    }

    public function testCoverageThresholdIsConfigurable(): void
    {
        $lenient = new BookingHistorySeedBuilder(minCoverage: 0.2);
        $this->add($lenient, '26378191', 'people.catering', 27, 21);
        $this->add($lenient, '26378191', null, 73, 40);
        $this->assertCount(1, $lenient->candidates(), 'nižší práh kandidáta pustí');

        $strict = new BookingHistorySeedBuilder(minCoverage: 0.9);
        $this->add($strict, '26378191', 'people.catering', 60, 21);
        $this->add($strict, '26378191', null, 40, 20);
        $this->assertSame([], $strict->candidates(), 'vyšší práh ho zastaví');

        $this->assertSame(0.8, (new BookingHistorySeedBuilder())->minShare());
        $this->assertSame(3, (new BookingHistorySeedBuilder())->minDocCount());
        $this->assertSame(0.5, (new BookingHistorySeedBuilder())->minCoverage());
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
