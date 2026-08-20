<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryQuality;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryRecord;

/** Metriky kvality zdroje (D33). */
class BookingHistoryQualityTest extends TestCase
{
    private function record(
        ?string $rowText,
        ?string $itemName = null,
        ?string $account = '518202',
        ?string $companyId = '26378191',
        int $rows = 10,
        int $docs = 5,
        ?float $amount = 1000.0,
    ): BookingHistoryRecord {
        return new BookingHistoryRecord(
            companyId: $companyId,
            account: $account,
            itemCode: null,
            itemName: $itemName,
            rowText: $rowText,
            docCount: $docs,
            rowCount: $rows,
            totalAmount: $amount,
            firstDate: null,
            lastDate: null,
        );
    }

    public function testDegeneracyKindsAndShares(): void
    {
        $quality = new BookingHistoryQuality();
        $quality->add($this->record('Paušál za internet'));              // obsahonosný
        $quality->add($this->record(null));                              // empty
        $quality->add($this->record('  ', rows: 5));                     // empty (whitespace)
        $quality->add($this->record('Internetové služby', 'Internetové  služby')); // == itemName
        $quality->add($this->record('518202'));                          // == account

        $this->assertSame(5, $quality->records());
        $this->assertSame(45, $quality->rows());
        $this->assertSame(4, $quality->degenerateRecords());
        $this->assertSame(35, $quality->degenerateRows());
        $this->assertSame(10, $quality->contentfulRows());
        $this->assertEqualsWithDelta(35 / 45, $quality->degenerateShare(), 0.0001);

        $byKind = $quality->degenerateByKind();
        $this->assertSame(['records' => 2, 'rows' => 15], $byKind[BookingHistoryRecord::DEGENERACY_EMPTY]);
        $this->assertSame(['records' => 1, 'rows' => 10], $byKind[BookingHistoryRecord::DEGENERACY_ITEM_NAME]);
        $this->assertSame(['records' => 1, 'rows' => 10], $byKind[BookingHistoryRecord::DEGENERACY_ACCOUNT]);
    }

    public function testPerAccountStatsAndMissingFields(): void
    {
        $quality = new BookingHistoryQuality();
        $quality->add($this->record('Paušál', account: '518202', rows: 10, docs: 4, amount: 1000.0));
        $quality->add($this->record('518202', account: '518202', rows: 5, docs: 2, amount: 500.0));
        $quality->add($this->record('Papír', account: '501100', rows: 20, docs: 8, amount: 3000.0));
        $quality->add($this->record('Bez účtu', account: null, companyId: null, rows: 7, docs: 3, amount: null));

        $accounts = $quality->accounts();
        $this->assertSame(15, $accounts['518202']['rows']);
        $this->assertSame(6, $accounts['518202']['docs']);
        $this->assertSame(5, $accounts['518202']['degenerateRows'], 'text == číslo účtu se počítá per účet');
        $this->assertSame(0, $accounts['501100']['degenerateRows']);
        $this->assertArrayNotHasKey('', $accounts);

        $this->assertSame(['records' => 1, 'rows' => 7], $quality->missingAccount());
        $this->assertSame(['records' => 1, 'rows' => 7], $quality->missingCompanyId());
        $this->assertSame(1, $quality->recordsWithoutAmount());
        $this->assertSame(4500.0, $quality->amount());
        $this->assertSame(17, $quality->docs());
    }

    public function testTopAccountsAreSortedByAmountThenRows(): void
    {
        $quality = new BookingHistoryQuality();
        $quality->add($this->record('a', account: '501100', rows: 100, amount: 100.0));
        $quality->add($this->record('b', account: '518202', rows: 1, amount: 9000.0));
        $quality->add($this->record('c', account: '502100', rows: 50, amount: 500.0));

        $top = $quality->topAccounts(2);
        $this->assertCount(2, $top);
        $this->assertSame('518202', $top[0]['account']);
        $this->assertSame('502100', $top[1]['account']);
        $this->assertCount(3, $quality->topAccounts(0), 'limit 0 = bez omezení');
    }

    public function testEmptyAccumulator(): void
    {
        $quality = new BookingHistoryQuality();
        $this->assertSame(0, $quality->records());
        $this->assertSame(0.0, $quality->degenerateShare());
        $this->assertSame([], $quality->topAccounts());
    }
}
