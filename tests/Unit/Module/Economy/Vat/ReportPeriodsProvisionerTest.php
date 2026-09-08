<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\ReportPeriodsProvisioner;

final class ReportPeriodsProvisionerTest extends TestCase
{
    public function testMonthlyCandidate(): void
    {
        $c = ReportPeriodsProvisioner::candidateRange(ReportPeriodsProvisioner::KIND_MONTHLY, '2026-02-14');
        $this->assertSame(['begin' => '2026-02-01', 'end' => '2026-02-28', 'name' => '02/2026'], $c);
    }

    public function testQuarterlyCandidate(): void
    {
        $c = ReportPeriodsProvisioner::candidateRange(ReportPeriodsProvisioner::KIND_QUARTERLY, '2026-12-31');
        $this->assertSame(['begin' => '2026-10-01', 'end' => '2026-12-31', 'name' => 'Q4/2026'], $c);
        $c = ReportPeriodsProvisioner::candidateRange(ReportPeriodsProvisioner::KIND_QUARTERLY, '2026-05-01');
        $this->assertSame('Q2/2026', $c['name']);
        $this->assertSame('2026-04-01', $c['begin']);
    }

    public function testUnknownKindFallsBackToMonthly(): void
    {
        $this->assertSame('07/2026', ReportPeriodsProvisioner::candidateRange(0, '2026-07-04')['name']);
    }

    public function testClampToRegistrationValidity(): void
    {
        $c = ReportPeriodsProvisioner::candidateRange(2, '2026-02-10');
        $clamped = ReportPeriodsProvisioner::clampRange($c, '2026-01-15', '2026-03-10', ['prevEnd' => null, 'nextBegin' => null]);
        $this->assertSame(['begin' => '2026-01-15', 'end' => '2026-03-10', 'name' => 'Q1/2026'], $clamped);
    }

    public function testClampAgainstNeighbours(): void
    {
        // Kandidát Q1, existuje leden (do 31.1.) a od 20.3. další instance
        // → kandidát se smrskne na 1.2.–19.3. (bez překryvu)
        $c = ReportPeriodsProvisioner::candidateRange(2, '2026-02-10');
        $clamped = ReportPeriodsProvisioner::clampRange($c, null, null, ['prevEnd' => '2026-01-31', 'nextBegin' => '2026-03-20']);
        $this->assertSame('2026-02-01', $clamped['begin']);
        $this->assertSame('2026-03-19', $clamped['end']);
    }

    // ── Zákonný počátek typu výstupu (#58) ──────────────────────────────────

    public function testLowerBoundIsLaterOfRegistrationAndTypeValidFrom(): void
    {
        $this->assertNull(ReportPeriodsProvisioner::lowerBound(null, null));
        $this->assertSame('2016-01-01', ReportPeriodsProvisioner::lowerBound(null, '2016-01-01'));
        $this->assertSame('2010-05-01', ReportPeriodsProvisioner::lowerBound('2010-05-01', null));
        $this->assertSame('2016-01-01', ReportPeriodsProvisioner::lowerBound('2010-05-01', '2016-01-01'), 'registrace starší než KH → KH');
        $this->assertSame('2020-03-01', ReportPeriodsProvisioner::lowerBound('2020-03-01', '2016-01-01'), 'registrace mladší než KH → registrace');
    }

    public function testClampToTypeValidFromCutsCandidateBegin(): void
    {
        // Kandidát Q1/2016 čtvrtletního KH, kdyby počátek výstupu padl dovnitř
        // čtvrtletí — začátek se ořízne stejně jako platností registrace.
        $c = ReportPeriodsProvisioner::candidateRange(2, '2016-02-10');
        $lower = ReportPeriodsProvisioner::lowerBound('2010-01-01', '2016-01-15');
        $clamped = ReportPeriodsProvisioner::clampRange($c, $lower, null, ['prevEnd' => null, 'nextBegin' => null]);
        $this->assertSame(['begin' => '2016-01-15', 'end' => '2016-03-31', 'name' => 'Q1/2016'], $clamped);
    }

    public function testClampKeepsCandidateWhenNoConstraintBinds(): void
    {
        $c = ReportPeriodsProvisioner::candidateRange(1, '2026-02-10');
        $clamped = ReportPeriodsProvisioner::clampRange($c, '2025-01-01', null, ['prevEnd' => '2025-12-31', 'nextBegin' => '2026-06-01']);
        $this->assertSame($c, $clamped);
    }
}
