<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\FilingRounding;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatReturnCalculator;

/**
 * Zaokrouhlení podaných hodnot (#55 D17). Klíčový případ je ten, kde se
 * součet zaokrouhlených řádků liší od zaokrouhleného součtu — kdyby se
 * dopočty zaokrouhlovaly až nakonec, podané XML by nesouhlasilo.
 */
class FilingRoundingTest extends TestCase
{
    /** @param array<int, array<string, float>> $rows */
    private function calculated(array $rows, array $computed = []): array
    {
        return ['rows' => $rows, 'computed' => $computed];
    }

    private function row(float $base, float $taxFull = 0.0, float $taxReduced = 0.0): array
    {
        return ['base' => $base, 'taxFull' => $taxFull, 'taxReduced' => $taxReduced];
    }

    // ── Řádkové zaokrouhlení a dopočty ──────────────────────────────────

    public function testRowsAreRoundedIndividually(): void
    {
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            1 => $this->row(1000.49, 210.51),
            2 => $this->row(500.50, 60.49),
        ]), 1.0);

        $this->assertSame(1000.0, $filed[1]['base']);
        $this->assertSame(211.0, $filed[1]['taxFull']);
        $this->assertSame(501.0, $filed[2]['base'], 'půlka se zaokrouhluje nahoru');
        $this->assertSame(60.0, $filed[2]['taxFull']);
    }

    public function testDeductionTotalIsSumOfRoundedRowsNotRoundedSum(): void
    {
        // Tři odpočtové řádky po 0,40 Kč: součet zaokrouhlených = 0,
        // zaokrouhlený součet = 1. Úřad čeká první variantu.
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            40 => $this->row(100.0, 0.40),
            41 => $this->row(100.0, 0.40),
            43 => $this->row(100.0, 0.40),
        ]), 1.0);

        $this->assertSame(0.0, $filed[46]['taxFull'], 'součet zaokrouhlených řádků');
        $this->assertSame(300.0, $filed[46]['base']);
    }

    public function testOutputTaxTotalIsSumOfRoundedRows(): void
    {
        // 62 = Σ 1..13 − 61. Řádky po 10,60 → zaokrouhleně 11 + 11 = 22,
        // zaokrouhlený přesný součet 21,20 by dal 21.
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            1 => $this->row(50.0, 10.60),
            2 => $this->row(50.0, 10.60),
        ]), 1.0);

        $this->assertSame(22.0, $filed[62]['taxFull']);
    }

    public function testRow61IsSubtractedFromOutputTax(): void
    {
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            1  => $this->row(1000.0, 210.0),
            61 => $this->row(0.0, 10.0),
        ]), 1.0);

        $this->assertSame(200.0, $filed[62]['taxFull']);
    }

    public function testRow52IsRoundedReducedTimesCoefficient(): void
    {
        // Krácený nárok 2× 500,40 → zaokrouhleně 500 + 500 = 1000,
        // × 0,80 = 800.
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            40 => $this->row(2000.0, 0.0, 500.40),
            41 => $this->row(2000.0, 0.0, 500.40),
        ]), 0.80);

        $this->assertSame(1000.0, $filed[46]['taxReduced']);
        $this->assertSame(800.0, $filed[52]['taxFull']);
        $this->assertSame(0.0, $filed[46]['taxFull'], 'plný odpočet je nulový');
        $this->assertSame(800.0, $filed[63]['taxFull'], '63 = 46 + 52');
    }

    public function testRow52RoundsToUnit(): void
    {
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            40 => $this->row(1000.0, 0.0, 101.0),
        ]), 0.55);

        // 101 × 0,55 = 55,55 → 56 Kč
        $this->assertSame(56.0, $filed[52]['taxFull']);
    }

    public function testLiabilityGoesToRow64(): void
    {
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            1  => $this->row(10000.0, 2100.0),
            40 => $this->row(5000.0, 1050.0),
        ]), 1.0);

        $this->assertSame(2100.0, $filed[62]['taxFull']);
        $this->assertSame(1050.0, $filed[63]['taxFull']);
        $this->assertSame(1050.0, $filed[64]['taxFull']);
        $this->assertSame(0.0, $filed[65]['taxFull']);
        $this->assertSame(0.0, $filed[66]['taxFull']);
    }

    public function testExcessDeductionGoesToRow65(): void
    {
        $filed = FilingRounding::vatReturnFiled($this->calculated([
            1  => $this->row(1000.0, 210.0),
            40 => $this->row(5000.0, 1050.0),
        ]), 1.0);

        $this->assertSame(0.0, $filed[64]['taxFull']);
        $this->assertSame(840.0, $filed[65]['taxFull']);
    }

    public function testComputedRowsFromCalculatorAreNotRoundedTwice(): void
    {
        // Kalkulátor dává dopočty v `computed`; kdyby se omylem zaokrouhlily
        // i z `rows`, přepsaly by přepočet ze zaokrouhlených řádků.
        $filed = FilingRounding::vatReturnFiled($this->calculated(
            [1 => $this->row(100.0, 10.60), 2 => $this->row(100.0, 10.60)],
            [62 => $this->row(0.0, 21.20)],
        ), 1.0);

        $this->assertSame(22.0, $filed[62]['taxFull']);
    }

    // ── Přesné hodnoty vedle podaných ───────────────────────────────────

    public function testExactRowsMergesRowsAndComputed(): void
    {
        $exact = FilingRounding::exactRows($this->calculated(
            [1 => $this->row(1000.49, 210.51)],
            [62 => $this->row(0.0, 210.51), 64 => $this->row(0.0, 210.51)],
        ));

        $this->assertSame([1, 62, 64, 66], array_keys($exact));
        $this->assertSame(1000.49, $exact[1]['base'], 'přesné hodnoty se nezaokrouhlují');
        $this->assertSame(210.51, $exact[62]['taxFull']);
        $this->assertSame(0.0, $exact[66]['taxFull'], 'ř. 66 existuje vždy, i nulový');
    }

    // ── Dodatečné přiznání ──────────────────────────────────────────────

    public function testSupplementaryDiffCarriesChangeOnRow66(): void
    {
        $previous = FilingRounding::vatReturnFiled($this->calculated([
            1  => $this->row(10000.0, 2100.0),
            40 => $this->row(5000.0, 1050.0),
        ]), 1.0);
        $current = FilingRounding::vatReturnFiled($this->calculated([
            1  => $this->row(12000.0, 2520.0),
            40 => $this->row(5000.0, 1050.0),
        ]), 1.0);

        $diff = FilingRounding::vatReturnDiff($current, $previous);

        $this->assertSame(2000.0, $diff[1]['base'], 'rozdíl základu');
        $this->assertSame(420.0, $diff[1]['taxFull']);
        $this->assertSame(0.0, $diff[40]['taxFull'], 'nedotčený řádek je v rozdílu nulový');
        $this->assertSame(0.0, $diff[64]['taxFull'], 'dodatečné nevykazuje vlastní daň');
        $this->assertSame(0.0, $diff[65]['taxFull']);
        $this->assertSame(420.0, $diff[66]['taxFull'], 'změna daňové povinnosti');
    }

    public function testSupplementaryDiffOfLowerLiabilityIsNegative(): void
    {
        $previous = FilingRounding::vatReturnFiled($this->calculated([
            1 => $this->row(10000.0, 2100.0),
        ]), 1.0);
        $current = FilingRounding::vatReturnFiled($this->calculated([
            1 => $this->row(8000.0, 1680.0),
        ]), 1.0);

        $diff = FilingRounding::vatReturnDiff($current, $previous);
        $this->assertSame(-420.0, $diff[66]['taxFull']);
    }

    public function testSupplementaryDiffIncludesRowsThatDisappeared(): void
    {
        // Řádek 2 v novém výpočtu není — v rozdílu musí vyjít záporně,
        // jinak by dodatečné přiznání tvrdilo, že se nezměnil.
        $previous = FilingRounding::vatReturnFiled($this->calculated([
            1 => $this->row(10000.0, 2100.0),
            2 => $this->row(1000.0, 120.0),
        ]), 1.0);
        $current = FilingRounding::vatReturnFiled($this->calculated([
            1 => $this->row(10000.0, 2100.0),
        ]), 1.0);

        $diff = FilingRounding::vatReturnDiff($current, $previous);
        $this->assertArrayHasKey(2, $diff);
        $this->assertSame(-1000.0, $diff[2]['base']);
        $this->assertSame(-120.0, $diff[2]['taxFull']);
        $this->assertSame(-120.0, $diff[66]['taxFull']);
    }

    // ── Ostatní jednotky ────────────────────────────────────────────────

    public function testHalerUnitLeavesValuesUntouched(): void
    {
        $filed = FilingRounding::vatReturnFiled(
            $this->calculated([1 => $this->row(1000.49, 210.51)]),
            1.0,
            FilingRounding::UNIT_HALER,
        );
        $this->assertSame(1000.49, $filed[1]['base']);
        $this->assertSame(210.51, $filed[1]['taxFull']);
    }

    public function testRecapitulativeValueRoundsUp(): void
    {
        $this->assertSame(1001.0, FilingRounding::recapitulativeValueFiled(1000.01));
        $this->assertSame(1000.0, FilingRounding::recapitulativeValueFiled(1000.0));
        $this->assertSame(1001.0, FilingRounding::recapitulativeValueFiled(1000.49), 'nahoru, ne round');
    }

    public function testRecapitulativeValueOnHalerUnitIsExact(): void
    {
        $this->assertSame(
            1000.49,
            FilingRounding::recapitulativeValueFiled(1000.49, FilingRounding::UNIT_HALER),
        );
    }

    // ── Návaznost na živý kalkulátor ────────────────────────────────────

    public function testRoundingOnRealCalculatorOutput(): void
    {
        $mapping = new VatOutputsMapping([
            'vatOutputs' => [
                'cz-120' => ['dp3' => ['row' => 1], 'kh' => null, 'sh' => null],
                'cz-110' => ['dp3' => ['row' => 40, 'col' => 'full'], 'kh' => null, 'sh' => null],
            ],
        ]);
        $docs = [
            ['id' => 1, 'recap' => [
                ['vat_code' => 'cz-120', 'vat_pct' => 21.0, 'base_dom' => 1000.30, 'tax_dom' => 210.06],
            ]],
            ['id' => 2, 'recap' => [
                ['vat_code' => 'cz-120', 'vat_pct' => 21.0, 'base_dom' => 2000.40, 'tax_dom' => 420.08],
                ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base_dom' => 500.50, 'tax_dom' => 105.11],
            ]],
        ];

        $calculated = (new VatReturnCalculator($mapping))->calculate($docs);
        $exact      = FilingRounding::exactRows($calculated);
        $filed      = FilingRounding::vatReturnFiled($calculated, 1.0);

        $this->assertSame(630.14, $exact[1]['taxFull'], 'přesná daň na výstupu');
        $this->assertSame(630.0, $filed[1]['taxFull']);
        $this->assertSame(105.0, $filed[46]['taxFull']);
        $this->assertSame(525.0, $filed[64]['taxFull'], '630 − 105 ze zaokrouhlených řádků');
        // Přesný výpočet by dal 630,14 − 105,11 = 525,03.
        $this->assertSame(525.03, round($exact[64]['taxFull'], 2));
    }
}
