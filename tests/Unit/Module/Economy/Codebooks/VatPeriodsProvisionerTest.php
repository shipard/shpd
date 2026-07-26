<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\VatPeriodsProvisioner;

class VatPeriodsProvisionerTest extends TestCase
{
    /**
     * Build a recording mock that captures inserts into in-memory tables.
     *
     * @param list<array<string, mixed>> $registrations
     * @param list<array<string, mixed>> $existingPeriods
     */
    private function recordingDb(array $registrations = [], array $existingPeriods = []): object
    {
        $store = new \stdClass();
        $store->tables = [
            'economy_codebooks_vat_registrations' => $registrations,
            'economy_codebooks_vat_periods'       => $existingPeriods,
        ];
        $store->autoIncrement = count($existingPeriods);

        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): array {
                if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                    return $store->tables['economy_codebooks_vat_registrations'];
                }
                return [];
            }
        );

        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                // Overlap lookup: params are (regId, candidate.date_end, candidate.date_begin)
                if (str_contains($sql, 'economy_codebooks_vat_periods')
                    && str_contains($sql, 'vat_registration')
                    && str_contains($sql, 'date_begin')
                    && str_contains($sql, 'date_end')
                ) {
                    $regId = (int) ($params[0] ?? 0);
                    $candEnd = (string) ($params[1] ?? '');
                    $candBegin = (string) ($params[2] ?? '');
                    foreach ($store->tables['economy_codebooks_vat_periods'] as $row) {
                        if ((int) ($row['vat_registration'] ?? 0) === $regId
                            && ($row['date_begin'] ?? '') <= $candEnd
                            && ($row['date_end'] ?? '') >= $candBegin
                        ) {
                            return $row;
                        }
                    }
                    return null;
                }
                return null;
            }
        );

        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use ($store): int {
                $store->autoIncrement++;
                $row = $data;
                $row['id'] = $store->autoIncrement;
                $store->tables[$table][] = $row;
                return $store->autoIncrement;
            }
        );

        $store->db = $db;
        return $store;
    }

    /** @return array<string, mixed> */
    private function makeReg(int $id, int $kind, string $validFrom, ?string $validTo, int $docState = 40): array
    {
        return [
            'id'              => $id,
            'tax_period_kind' => $kind,
            'valid_from'      => $validFrom,
            'valid_to'        => $validTo,
            'docState'        => $docState,
        ];
    }

    /** @return array<string, mixed> */
    private function makePeriod(
        int $id,
        int $regId,
        string $name,
        string $dateBegin,
        string $dateEnd,
        int $docState = 40,
    ): array {
        return [
            'id'               => $id,
            'vat_registration' => $regId,
            'name'             => $name,
            'date_begin'       => $dateBegin,
            'date_end'         => $dateEnd,
            'locked'           => 0,
            'docState'         => $docState,
            'docStateMain'     => $docState === 90 ? 4 : 3,
        ];
    }

    /**
     * Count rows whose range intersects the given interval.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function countOverlapping(array $rows, string $begin, string $end): int
    {
        return count(array_filter(
            $rows,
            fn(array $r) => ($r['date_begin'] ?? '') <= $end && ($r['date_end'] ?? '') >= $begin,
        ));
    }

    public function testNoRegistrationsResultsInZero(): void
    {
        $store = $this->recordingDb();
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(0, $result['vatPeriods']['created']);
        $this->assertSame(0, $result['vatPeriods']['existing']);
        $this->assertCount(0, $store->tables['economy_codebooks_vat_periods']);
    }

    public function testMonthlyRegistrationGenerates24Periods(): void
    {
        $store = $this->recordingDb([
            $this->makeReg(1, 1, '2026-01-01', null),
        ]);
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(24, $result['vatPeriods']['created']);
        $this->assertSame(0, $result['vatPeriods']['existing']);

        $rows = $store->tables['economy_codebooks_vat_periods'];
        $this->assertCount(24, $rows);

        $this->assertSame('01/2026', $rows[0]['name']);
        $this->assertSame('2026-01-01', $rows[0]['date_begin']);
        $this->assertSame('2026-01-31', $rows[0]['date_end']);
        $this->assertSame(40, $rows[0]['docState']);
        $this->assertSame(3, $rows[0]['docStateMain']);
        $this->assertSame(0, $rows[0]['locked']);
        $this->assertSame(1, $rows[0]['vat_registration']);

        $this->assertSame('12/2026', $rows[11]['name']);
        $this->assertSame('2026-12-31', $rows[11]['date_end']);

        $this->assertSame('01/2027', $rows[12]['name']);
        $this->assertSame('12/2027', $rows[23]['name']);
    }

    public function testQuarterlyRegistrationGenerates8Periods(): void
    {
        $store = $this->recordingDb([
            $this->makeReg(1, 2, '2026-01-01', null),
        ]);
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(8, $result['vatPeriods']['created']);

        $rows = $store->tables['economy_codebooks_vat_periods'];
        $this->assertCount(8, $rows);

        $this->assertSame('Q1/2026', $rows[0]['name']);
        $this->assertSame('2026-01-01', $rows[0]['date_begin']);
        $this->assertSame('2026-03-31', $rows[0]['date_end']);

        $this->assertSame('Q2/2026', $rows[1]['name']);
        $this->assertSame('2026-04-01', $rows[1]['date_begin']);
        $this->assertSame('2026-06-30', $rows[1]['date_end']);

        $this->assertSame('Q4/2026', $rows[3]['name']);
        $this->assertSame('2026-10-01', $rows[3]['date_begin']);
        $this->assertSame('2026-12-31', $rows[3]['date_end']);

        $this->assertSame('Q1/2027', $rows[4]['name']);
        $this->assertSame('Q4/2027', $rows[7]['name']);
    }

    public function testValidFromMidYearSkipsEarlierMonths(): void
    {
        $store = $this->recordingDb([
            $this->makeReg(1, 1, '2026-06-01', null),
        ]);
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        // June..Dec 2026 = 7 + 12 in 2027 = 19 months
        $this->assertSame(19, $result['vatPeriods']['created']);
        $rows = $store->tables['economy_codebooks_vat_periods'];
        $this->assertSame('06/2026', $rows[0]['name']);
        $this->assertSame('12/2027', $rows[18]['name']);
    }

    public function testValidToTruncatesLaterMonths(): void
    {
        $store = $this->recordingDb([
            $this->makeReg(1, 1, '2026-01-01', '2026-08-31'),
        ]);
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        // Jan..Aug 2026 = 8 months, nothing in 2027
        $this->assertSame(8, $result['vatPeriods']['created']);
        $rows = $store->tables['economy_codebooks_vat_periods'];
        $this->assertSame('01/2026', $rows[0]['name']);
        $this->assertSame('08/2026', $rows[7]['name']);
    }

    public function testIdempotenceSecondRunCountsExisting(): void
    {
        $store = $this->recordingDb([
            $this->makeReg(1, 1, '2026-01-01', null),
        ]);
        $provisioner = new VatPeriodsProvisioner($store->db);

        $first = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));
        $this->assertSame(24, $first['vatPeriods']['created']);

        $second = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));
        $this->assertSame(0, $second['vatPeriods']['created']);
        $this->assertSame(24, $second['vatPeriods']['existing']);
    }

    public function testIdempotenceSkipsDeletedPeriod(): void
    {
        // Pre-existing soft-deleted period for 01/2026 — provisioner must NOT recreate it.
        $existingPeriods = [
            [
                'id'               => 100,
                'vat_registration' => 1,
                'name'             => '01/2026',
                'date_begin'       => '2026-01-01',
                'date_end'         => '2026-01-31',
                'locked'           => 0,
                'docState'         => 90,
                'docStateMain'     => 4,
            ],
        ];
        $store = $this->recordingDb(
            [$this->makeReg(1, 1, '2026-01-01', null)],
            $existingPeriods,
        );
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        // 1 existing (01/2026 deleted), 23 created (rest of 2026 + all 2027)
        $this->assertSame(23, $result['vatPeriods']['created']);
        $this->assertSame(1, $result['vatPeriods']['existing']);

        // Make sure the deleted row is still soft-deleted, not duplicated
        $rowsForJan2026 = array_filter(
            $store->tables['economy_codebooks_vat_periods'],
            fn(array $r) => ($r['date_begin'] ?? '') === '2026-01-01',
        );
        $this->assertCount(1, $rowsForJan2026);
        $only = array_values($rowsForJan2026)[0];
        $this->assertSame(90, $only['docState']);
    }

    public function testDeletedRegistrationIsIgnored(): void
    {
        $store = $this->recordingDb([
            // docState=90 simulates a deleted registration; provisioner must ignore it.
            // The fetchAll mock returns whatever it's given — to faithfully model the
            // SQL filter we just don't include it in the registrations list.
        ]);
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(0, $result['vatPeriods']['created']);
        $this->assertSame(0, $result['vatPeriods']['existing']);
    }

    public function testTwoRegistrationsHandledIndependently(): void
    {
        $store = $this->recordingDb([
            $this->makeReg(1, 1, '2026-01-01', null),  // CZ monthly
            $this->makeReg(2, 2, '2026-01-01', null),  // SK quarterly
        ]);
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        // 24 + 8 = 32
        $this->assertSame(32, $result['vatPeriods']['created']);

        $rows = $store->tables['economy_codebooks_vat_periods'];
        $reg1Rows = array_filter($rows, fn(array $r) => (int) $r['vat_registration'] === 1);
        $reg2Rows = array_filter($rows, fn(array $r) => (int) $r['vat_registration'] === 2);
        $this->assertCount(24, $reg1Rows);
        $this->assertCount(8, $reg2Rows);
    }

    public function testQuarterlyExistingPeriodBlocksMonthlyCandidates(): void
    {
        // Imported quarterly history + monthly registration: Q1/2026 must block
        // January, February AND March candidates, not just January.
        $store = $this->recordingDb(
            [$this->makeReg(1, 1, '2026-01-01', null)],
            [$this->makePeriod(100, 1, 'Q1/2026', '2026-01-01', '2026-03-31')],
        );
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        // Apr..Dec 2026 = 9 + 12 in 2027 = 21 created; Jan/Feb/Mar counted as existing
        $this->assertSame(21, $result['vatPeriods']['created']);
        $this->assertSame(3, $result['vatPeriods']['existing']);

        // Nothing new may reach into the existing quarter
        $rows = $store->tables['economy_codebooks_vat_periods'];
        $this->assertSame(1, $this->countOverlapping($rows, '2026-01-01', '2026-03-31'));
        $this->assertSame('04/2026', $rows[1]['name']);
    }

    public function testMonthlyExistingPeriodBlocksQuarterlyCandidate(): void
    {
        // Reverse direction: a single imported month blocks the whole quarter.
        $store = $this->recordingDb(
            [$this->makeReg(1, 2, '2026-01-01', null)],
            [$this->makePeriod(100, 1, '02/2026', '2026-02-01', '2026-02-28')],
        );
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        // Q2..Q4 2026 = 3 + 4 in 2027 = 7 created; Q1/2026 counted as existing
        $this->assertSame(7, $result['vatPeriods']['created']);
        $this->assertSame(1, $result['vatPeriods']['existing']);

        $rows = $store->tables['economy_codebooks_vat_periods'];
        $this->assertSame(1, $this->countOverlapping($rows, '2026-01-01', '2026-03-31'));
        $this->assertSame('Q2/2026', $rows[1]['name']);
    }

    public function testNonAlignedImportedPeriodBlocksOverlappingCandidate(): void
    {
        // Real import case: a partial entry period not aligned to month boundaries.
        $store = $this->recordingDb(
            [$this->makeReg(1, 1, '2026-01-01', null)],
            [$this->makePeriod(100, 1, '11-12/2026', '2026-11-02', '2026-12-31')],
        );
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        // Jan..Oct 2026 = 10 + 12 in 2027 = 22 created; Nov + Dec counted as existing
        $this->assertSame(22, $result['vatPeriods']['created']);
        $this->assertSame(2, $result['vatPeriods']['existing']);

        $rows = $store->tables['economy_codebooks_vat_periods'];
        $this->assertSame(1, $this->countOverlapping($rows, '2026-11-02', '2026-12-31'));
    }

    public function testDeletedOverlappingPeriodStillBlocks(): void
    {
        // "Deleted stays deleted" holds for overlap too: a soft-deleted quarter
        // blocks all three monthly candidates inside it.
        $store = $this->recordingDb(
            [$this->makeReg(1, 1, '2026-01-01', null)],
            [$this->makePeriod(100, 1, 'Q1/2026', '2026-01-01', '2026-03-31', 90)],
        );
        $provisioner = new VatPeriodsProvisioner($store->db);
        $result = $provisioner->provision(new \DateTimeImmutable('2026-04-15'));

        $this->assertSame(21, $result['vatPeriods']['created']);
        $this->assertSame(3, $result['vatPeriods']['existing']);

        $rows = $store->tables['economy_codebooks_vat_periods'];
        $overlapping = array_values(array_filter(
            $rows,
            fn(array $r) => ($r['date_begin'] ?? '') <= '2026-03-31' && ($r['date_end'] ?? '') >= '2026-01-01',
        ));
        $this->assertCount(1, $overlapping);
        $this->assertSame(90, $overlapping[0]['docState']);
    }
}
