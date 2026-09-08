<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

/**
 * Živé přiznání k DPH (DPHDP3): sumace base/tax per (řádek, sloupec)
 * z mapování + dopočítané řádky (referenční logika old_shipard
 * VatReturnReport::calcTaxReturn, ř. 52 navíc — starý Shipard krácený
 * sloupec nikdy nevyplňoval):
 *
 *   46 = Σ 40..45 (odpočty, oba sloupce),
 *   52 = Σ krácený sloupec 40..45 × zálohový koeficient odpočtu (#59 D13;
 *        koeficient dodá volající z DeductionCoefficientResolver, default
 *        1,00 = plný nárok),
 *   62 = Σ 1..13 − 61 (daň na výstupu),
 *   63 = 46 + 52 + 53 + 60 — ř. 53 (roční vypořádání) a 60 (úprava
 *        odpočtu) jsou mimo scope a vstupují nulové,
 *   64 / 65 = vlastní daň / nadměrný odpočet z rozdílu 62 − 63.
 *
 * Plná přesnost — zaokrouhlování na celé Kč je věc XML (Fáze 3), řádek 66
 * (dodatečné přiznání) je mimo scope Fáze 1.
 */
final class VatReturnCalculator
{
    private const DEDUCTION_ROWS = [40, 41, 42, 43, 44, 45];
    private const OUTPUT_TAX_ROWS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];

    private const EMPTY_ROW = ['base' => 0.0, 'taxFull' => 0.0, 'taxReduced' => 0.0];

    public function __construct(private readonly VatOutputsMapping $mapping) {}

    /**
     * @param list<array<string, mixed>> $docs Doklady z VatDocumentSelection.
     * @param float $coefficient Zálohový koeficient odpočtu roku ⟨0; 1⟩ (ř. 52).
     * @return array{
     *     rows: array<int, array{base: float, taxFull: float, taxReduced: float}>,
     *     computed: array<int, array{base: float, taxFull: float, taxReduced: float}>,
     * } `rows` jen řádky s daty (klíč = číslo řádku, vzestupně);
     *   `computed` = 46, 52, 62, 63, 64, 65.
     */
    public function calculate(array $docs, float $coefficient = 1.0): array
    {
        if ($coefficient < 0.0 || $coefficient > 1.0) {
            throw new \InvalidArgumentException("VAT return: deduction coefficient {$coefficient} outside <0; 1>");
        }

        $rows = [];
        foreach ($docs as $doc) {
            foreach ($doc['recap'] ?? [] as $recapRow) {
                $dp3 = $this->mapping->dp3((string) $recapRow['vat_code']);
                if ($dp3 === null) {
                    continue;
                }
                $row = (int) $dp3['row'];
                $rows[$row] ??= self::EMPTY_ROW;
                $rows[$row]['base'] += (float) $recapRow['base_dom'];
                if (($dp3['col'] ?? null) === 'reduced') {
                    $rows[$row]['taxReduced'] += (float) $recapRow['tax_dom'];
                } else {
                    $rows[$row]['taxFull'] += (float) $recapRow['tax_dom'];
                }
            }
        }
        ksort($rows);
        $rows = array_map($this->roundRow(...), $rows);

        $computed = [];
        $computed[46] = $this->sumRows($rows, self::DEDUCTION_ROWS);

        // 52 = krácený nárok (ř. 40–45, sloupec krácený) × koeficient; výsledek
        // je nárok v Kč, proto ve sloupci daně (taxFull), základ 0.
        $computed[52] = self::EMPTY_ROW;
        $computed[52]['taxFull'] = round($computed[46]['taxReduced'] * $coefficient, 2);

        $computed[62] = $this->subtractRows(
            $this->sumRows($rows, self::OUTPUT_TAX_ROWS),
            $rows[61] ?? self::EMPTY_ROW,
        );

        // 63 = 46 + 52 + 53 + 60; ř. 53 (vypořádání) a 60 (úprava odpočtu)
        // zatím neexistují — vstupují nulové (mimo scope D13c).
        $computed[63] = self::EMPTY_ROW;
        $computed[63]['taxFull'] = $computed[46]['taxFull']
            + $computed[52]['taxFull']
            + ($rows[53]['taxFull'] ?? 0.0)
            + ($rows[60]['taxFull'] ?? 0.0);

        $totalTax = round($computed[62]['taxFull'] - $computed[63]['taxFull'], 2);
        $computed[64] = self::EMPTY_ROW;
        $computed[65] = self::EMPTY_ROW;
        if ($totalTax >= 0) {
            $computed[64]['taxFull'] = $totalTax;
        } else {
            $computed[65]['taxFull'] = -$totalTax;
        }

        return ['rows' => $rows, 'computed' => array_map($this->roundRow(...), $computed)];
    }

    /**
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $rows
     * @param list<int> $rowNumbers
     * @return array{base: float, taxFull: float, taxReduced: float}
     */
    private function sumRows(array $rows, array $rowNumbers): array
    {
        $sum = self::EMPTY_ROW;
        foreach ($rowNumbers as $number) {
            foreach ($rows[$number] ?? [] as $field => $value) {
                $sum[$field] += $value;
            }
        }
        return $sum;
    }

    /**
     * @param array{base: float, taxFull: float, taxReduced: float} $a
     * @param array{base: float, taxFull: float, taxReduced: float} $b
     * @return array{base: float, taxFull: float, taxReduced: float}
     */
    private function subtractRows(array $a, array $b): array
    {
        foreach ($b as $field => $value) {
            $a[$field] -= $value;
        }
        return $a;
    }

    /**
     * @param array{base: float, taxFull: float, taxReduced: float} $row
     * @return array{base: float, taxFull: float, taxReduced: float}
     */
    private function roundRow(array $row): array
    {
        return array_map(static fn (float $value): float => round($value, 2), $row);
    }
}
