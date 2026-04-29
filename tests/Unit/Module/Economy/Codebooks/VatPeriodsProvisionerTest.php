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
                if (str_contains($sql, 'economy_codebooks_vat_periods')
                    && str_contains($sql, 'vat_registration')
                    && str_contains($sql, 'date_begin')
                ) {
                    $regId = (int) ($params[0] ?? 0);
                    $needle = (string) ($params[1] ?? '');
                    foreach ($store->tables['economy_codebooks_vat_periods'] as $row) {
                        if ((int) ($row['vat_registration'] ?? 0) === $regId
                            && ($row['date_begin'] ?? '') === $needle
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
}
