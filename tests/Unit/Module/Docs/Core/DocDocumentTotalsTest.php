<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentTotalsTest extends TestCase
{
    private function doc(): TestableDocsHeadsDocument
    {
        return new TestableDocsHeadsDocument();
    }

    public function testSumTotalsRespectsSumFlags(): void
    {
        $recap = [
            ['base' => 100, 'tax' => 21, 'total' => 121, 'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1],
            ['base' => 50,  'tax' => 6,  'total' => 56,  'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1],
        ];
        $data = [];
        $this->doc()->sumTotalsPub($data, $recap);

        $this->assertSame(150.0, $data['total_base']);
        $this->assertSame(27.0, $data['total_vat']);
        $this->assertSame(177.0, $data['total_amount']);
        $this->assertSame(0.0, $data['total_rounding']);
    }

    public function testSumTotalsExcludesReverseChargePair(): void
    {
        $recap = [
            // Primary cz-115 (PDP) — nese odpočet (tax 42), ale sum_tax=0
            // a total jen základ (daň se dodavateli neplatí)
            ['base' => 200, 'tax' => 42, 'total' => 200, 'sum_base' => 1, 'sum_tax' => 0, 'sum_total' => 1],
            // Paired cz-203 (oddanění) — all sum_*=0
            ['base' => 200, 'tax' => 42, 'total' => 242, 'sum_base' => 0, 'sum_tax' => 0, 'sum_total' => 0],
        ];
        $data = [];
        $this->doc()->sumTotalsPub($data, $recap);

        // Only primary base + total counted; tax=0 from primary, paired excluded
        $this->assertSame(200.0, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat']);
        $this->assertSame(200.0, $data['total_amount']);
    }

    public function testApplyRoundingNoRounding(): void
    {
        // mode 0 still rounds to 2 decimals (storage precision)
        $this->assertSame(123.46, $this->doc()->applyRoundingPub(123.4567, 0));
    }

    public function testApplyRoundingToWholeUnits(): void
    {
        $this->assertSame(124.0, $this->doc()->applyRoundingPub(123.55, 1));
        $this->assertSame(123.0, $this->doc()->applyRoundingPub(123.45, 1));
        $this->assertSame(123.0, $this->doc()->applyRoundingPub(123.49, 1));
    }

    public function testApplyRoundingTo001(): void
    {
        $this->assertSame(123.46, $this->doc()->applyRoundingPub(123.456, 2));
        $this->assertSame(123.45, $this->doc()->applyRoundingPub(123.454, 2));
    }

    public function testApplyRoundingUpToWholeUnits(): void
    {
        $this->assertSame(124.0, $this->doc()->applyRoundingPub(123.05, 3));
        $this->assertSame(124.0, $this->doc()->applyRoundingPub(123.95, 3));
        $this->assertSame(123.0, $this->doc()->applyRoundingPub(123.00, 3));
        // Záporné částky (dobropisy): matematická sémantika ceil
        $this->assertSame(-1709.0, $this->doc()->applyRoundingPub(-1709.05, 3));
    }

    public function testApplyRoundingDownToWholeUnits(): void
    {
        $this->assertSame(123.0, $this->doc()->applyRoundingPub(123.05, 4));
        $this->assertSame(123.0, $this->doc()->applyRoundingPub(123.95, 4));
        $this->assertSame(123.0, $this->doc()->applyRoundingPub(123.00, 4));
        // Záporné částky (dobropisy): matematická sémantika floor
        $this->assertSame(-1710.0, $this->doc()->applyRoundingPub(-1709.05, 4));
    }

    public function testApplyTotalRoundingComputesDiff(): void
    {
        $data = ['total_amount' => 1234.56, 'total_rounding_mode' => 1];
        $this->doc()->applyTotalRoundingPub($data);

        // 1234.56 → 1235 (round half-up), diff = 0.44
        $this->assertSame(1235.0, $data['total_amount']);
        $this->assertEqualsWithDelta(0.44, $data['total_rounding'], 0.001);
    }

    public function testApplyTotalRoundingNoRoundingZeroDiff(): void
    {
        $data = ['total_amount' => 100.00, 'total_rounding_mode' => 0];
        $this->doc()->applyTotalRoundingPub($data);

        $this->assertSame(100.0, $data['total_amount']);
        $this->assertSame(0.0, $data['total_rounding']);
    }

    public function testApplyTotalRoundingUpComputesDiff(): void
    {
        $data = ['total_amount' => 1708.40, 'total_rounding_mode' => 3];
        $this->doc()->applyTotalRoundingPub($data);

        $this->assertSame(1709.0, $data['total_amount']);
        $this->assertEqualsWithDelta(0.60, $data['total_rounding'], 0.001);
    }

    public function testApplyTotalRoundingDownComputesDiff(): void
    {
        $data = ['total_amount' => 1709.05, 'total_rounding_mode' => 4];
        $this->doc()->applyTotalRoundingPub($data);

        $this->assertSame(1709.0, $data['total_amount']);
        $this->assertEqualsWithDelta(-0.05, $data['total_rounding'], 0.001);
    }

    public function testApplyTotalRoundingUpNegativeAmount(): void
    {
        // Dobropis: ceil(-1709.05) = -1709.0, diff +0.05
        $data = ['total_amount' => -1709.05, 'total_rounding_mode' => 3];
        $this->doc()->applyTotalRoundingPub($data);

        $this->assertSame(-1709.0, $data['total_amount']);
        $this->assertEqualsWithDelta(0.05, $data['total_rounding'], 0.001);
    }

    public function testApplyTotalRoundingDownNegativeAmount(): void
    {
        // Dobropis: floor(-1709.05) = -1710.0, diff -0.95
        $data = ['total_amount' => -1709.05, 'total_rounding_mode' => 4];
        $this->doc()->applyTotalRoundingPub($data);

        $this->assertSame(-1710.0, $data['total_amount']);
        $this->assertEqualsWithDelta(-0.95, $data['total_rounding'], 0.001);
    }

    public function testApplyDomesticAmountsHeadFromRecapSums(): void
    {
        $recap = [
            ['vat_code' => 'cz-101', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0,
             'base_dom' => 2500.0, 'tax_dom' => 525.0,
             'sum_base' => 1, 'sum_tax' => 1, 'is_reverse_pair' => 0],
        ];
        $data = ['total_amount' => 121.0, 'exchange_rate' => 25.0];
        $rows = [];
        $this->doc()->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertSame(2500.0, $data['total_base_dom']);
        $this->assertSame(525.0, $data['total_vat_dom']);
        $this->assertSame(3025.0, $data['total_amount_dom']);
        $this->assertSame(0.0, $data['total_rounding_dom']);
    }

    public function testApplyDomesticAmountsZeroRateFallsBackToOne(): void
    {
        $recap = [
            ['vat_code' => 'cz-101', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0,
             'base_dom' => 100.0, 'tax_dom' => 21.0,
             'sum_base' => 1, 'sum_tax' => 1, 'is_reverse_pair' => 0],
        ];
        $data = ['total_amount' => 121.0, 'exchange_rate' => 0.0];
        $rows = [];
        $this->doc()->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertSame(100.0, $data['total_base_dom']);
        $this->assertSame(21.0, $data['total_vat_dom']);
        $this->assertSame(121.0, $data['total_amount_dom']);
    }

    public function testApplyDomesticAmountsRespectsSumFlags(): void
    {
        // Reverse charge: paired oddanění line must not leak into head sums
        $recap = [
            ['vat_code' => 'cz-115', 'vat_pct' => 21.0, 'base' => 200.0, 'tax' => 42.0,
             'base_dom' => 200.0, 'tax_dom' => 42.0,
             'sum_base' => 1, 'sum_tax' => 0, 'is_reverse_pair' => 0],
            ['vat_code' => 'cz-203', 'vat_pct' => 21.0, 'base' => 200.0, 'tax' => 42.0,
             'base_dom' => 200.0, 'tax_dom' => 42.0,
             'sum_base' => 0, 'sum_tax' => 0, 'is_reverse_pair' => 1],
        ];
        $data = ['total_amount' => 200.0, 'exchange_rate' => 1.0];
        $rows = [];
        $this->doc()->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertSame(200.0, $data['total_base_dom']);
        $this->assertSame(0.0, $data['total_vat_dom']);
        $this->assertSame(200.0, $data['total_amount_dom']);
        $this->assertSame(0.0, $data['total_rounding_dom']);
    }

    // ── Z1: řádky mimo DPH rekapitulaci ─────────────────────────────────────

    public function testSumTotalsFromRowsWhenRecapEmpty(): void
    {
        // Doklad z období neplátcovství — řádky bez kódu, prázdná rekapitulace.
        // Po Z3 mají bezkódové řádky vat_amount 0 a vat_total = vat_base.
        $recap = [];
        $rows = [
            ['row_kind' => 1, 'vat_code' => null, 'vat_pct' => null,
             'vat_base' => 9490.0, 'vat_amount' => 0.0, 'vat_total' => 9490.0],
        ];
        $data = [];
        $this->doc()->sumTotalsPub($data, $recap, $rows);

        $this->assertSame(9490.0, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat']);
        $this->assertSame(9490.0, $data['total_amount']);
    }

    public function testSumTotalsMixedRecapAndOutsideRows(): void
    {
        // Kódový řádek jde přes recap; bezkódový přes fallback z řádků.
        $recap = [
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 1000.0, 'tax' => 210.0,
             'total' => 1210.0, 'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1],
        ];
        $rows = [
            ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21,
             'vat_base' => 1000.0, 'vat_amount' => 210.0, 'vat_total' => 1210.0],
            ['row_kind' => 1, 'vat_code' => null, 'vat_pct' => null,
             'vat_base' => 500.0, 'vat_amount' => 0.0, 'vat_total' => 500.0],
        ];
        $data = [];
        $this->doc()->sumTotalsPub($data, $recap, $rows);

        $this->assertSame(1500.0, $data['total_base']);
        $this->assertSame(210.0, $data['total_vat']);
        $this->assertSame(1710.0, $data['total_amount']);
    }

    public function testApplyDomesticAmountsIncludesRowsOutsideRecap(): void
    {
        // Prázdný recap, bezkódový řádek — _dom součty z řádků; invariant platí.
        $recap = [];
        $rows = [
            ['row_kind' => 1, 'vat_code' => null, 'vat_pct' => null,
             'vat_base' => 100.0, 'vat_amount' => 0.0, 'vat_total' => 100.0],
        ];
        $data = ['total_amount' => 100.0, 'exchange_rate' => 25.0];
        $this->doc()->applyDomesticAmountsPub($data, $rows, $recap);

        $this->assertSame(2500.0, $data['total_base_dom']);
        $this->assertSame(0.0, $data['total_vat_dom']);
        $this->assertSame(2500.0, $data['total_amount_dom']);
        // base_dom + vat_dom + rounding_dom == amount_dom
        $this->assertSame(0.0, $data['total_rounding_dom']);
        $this->assertSame(
            $data['total_amount_dom'],
            round($data['total_base_dom'] + $data['total_vat_dom'] + $data['total_rounding_dom'], 2),
        );
    }
}
