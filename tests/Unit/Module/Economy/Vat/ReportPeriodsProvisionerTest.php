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

    public function testClampKeepsCandidateWhenNoConstraintBinds(): void
    {
        $c = ReportPeriodsProvisioner::candidateRange(1, '2026-02-10');
        $clamped = ReportPeriodsProvisioner::clampRange($c, '2025-01-01', null, ['prevEnd' => '2025-12-31', 'nextBegin' => '2026-06-01']);
        $this->assertSame($c, $clamped);
    }
}
