<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

/**
 * Zaokrouhlení podaných hodnot (issue #55, D17). Zaokrouhlení je pravidlo
 * **podání**, ne živého reportu — živý report zůstává přesný, snapshot
 * nese obě hodnoty vedle sebe.
 *
 * Pravidlo přiznání DPHDP3 převzaté ze starého Shipardu
 * (`VatReturnReport::calcTaxReturn`) a ověřené proti podaným XML:
 *
 * 1. každý řádek formuláře se zaokrouhlí **samostatně** na jednotku
 *    (`roundingUnit`, v ČR celé Kč);
 * 2. dopočty 46 / 62 / 63 se počítají **ze zaokrouhlených řádků**, ne
 *    zaokrouhlením přesného součtu — v tom je celý smysl pravidla:
 *    součet zaokrouhlených se od zaokrouhleného součtu běžně liší
 *    o jednotky Kč a úřad čeká první variantu;
 * 3. ř. 52 = zaokrouhlený krácený nárok × zálohový koeficient (ř. 52 je
 *    dopočet nad zaokrouhleným ř. 46, ne nad přesným);
 * 4. ř. 64 / 65 = 62 − 63 podle znaménka (vlastní daň / nadměrný odpočet).
 *
 * Dodatečné přiznání je rozdíl zaokrouhlených řádků proti předchozímu
 * podání; ř. 64/65 jsou nulové a změnu daňové povinnosti nese ř. 66.
 *
 * Souhrnné hlášení zaokrouhluje hodnotu **nahoru** (`ceil`, věrně dle
 * `VatRSReport`); kontrolní hlášení se podává na haléře, tam je
 * zaokrouhlení identita.
 *
 * Čistá třída bez DB i bez configu — jednotku a koeficient dodá volající.
 */
final class FilingRounding
{
    /** Zaokrouhlovací jednotka celých korun. */
    public const UNIT_KORUNA = 1.0;

    /** Jednotka haléřů — podané hodnoty se rovnají přesným. */
    public const UNIT_HALER = 0.01;

    /** Dopočtené řádky přiznání (`is_computed`). */
    public const COMPUTED_ROWS = [46, 52, 62, 63, 64, 65, 66];

    private const DEDUCTION_ROWS = [40, 41, 42, 43, 44, 45];
    private const OUTPUT_TAX_ROWS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];

    private const EMPTY_ROW = ['base' => 0.0, 'taxFull' => 0.0, 'taxReduced' => 0.0];

    /**
     * Přesné hodnoty v jedné mapě — sumované řádky a dopočty tak, jak je
     * vrátil `VatReturnCalculator`, plus nulový ř. 66 (existuje jen
     * u dodatečného přiznání, ale v tabulce má být vždy).
     *
     * @param array{rows: array<int, array<string, float>>, computed: array<int, array<string, float>>} $calculated
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    public static function exactRows(array $calculated): array
    {
        $out = [];
        foreach ($calculated['rows'] as $row => $values) {
            $out[(int) $row] = self::normalizeRow($values);
        }
        foreach ($calculated['computed'] as $row => $values) {
            $out[(int) $row] = self::normalizeRow($values);
        }
        $out[66] ??= self::EMPTY_ROW;
        ksort($out);
        return $out;
    }

    /**
     * Podané hodnoty přiznání: zaokrouhlené řádky + dopočty nad nimi.
     *
     * @param array{rows: array<int, array<string, float>>, computed: array<int, array<string, float>>} $calculated
     * @param float $coefficient Zálohový koeficient odpočtu ⟨0; 1⟩ (ř. 52).
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    public static function vatReturnFiled(
        array $calculated,
        float $coefficient,
        float $unit = self::UNIT_KORUNA,
    ): array {
        $filed = [];
        foreach ($calculated['rows'] as $row => $values) {
            // Dopočty z `rows` (kalkulátor je tam nedává) by se zaokrouhlily
            // dvakrát — jednotlivé řádky jsou jen sumace z dokladů.
            if (in_array((int) $row, self::COMPUTED_ROWS, true)) {
                continue;
            }
            $filed[(int) $row] = self::quantizeRow(self::normalizeRow($values), $unit);
        }

        $filed[46] = self::sumRows($filed, self::DEDUCTION_ROWS);

        // Krácený nárok už zaokrouhlený × koeficient, znovu na jednotku.
        $filed[52] = self::EMPTY_ROW;
        $filed[52]['taxFull'] = self::quantize($filed[46]['taxReduced'] * $coefficient, $unit);

        $filed[62] = self::subtractRows(
            self::sumRows($filed, self::OUTPUT_TAX_ROWS),
            $filed[61] ?? self::EMPTY_ROW,
        );

        // 63 = 46 + 52 + 53 + 60; ř. 53 (roční vypořádání) a 60 (úprava
        // odpočtu) jsou mimo scope M1 a vstupují nulové.
        $filed[63] = self::EMPTY_ROW;
        $filed[63]['taxFull'] = self::round2(
            $filed[46]['taxFull']
            + $filed[52]['taxFull']
            + ($filed[53]['taxFull'] ?? 0.0)
            + ($filed[60]['taxFull'] ?? 0.0),
        );

        $total     = self::round2($filed[62]['taxFull'] - $filed[63]['taxFull']);
        $filed[64] = self::EMPTY_ROW;
        $filed[65] = self::EMPTY_ROW;
        $filed[66] = self::EMPTY_ROW;
        if ($total >= 0.0) {
            $filed[64]['taxFull'] = $total;
        } else {
            $filed[65]['taxFull'] = -$total;
        }

        ksort($filed);
        return $filed;
    }

    /**
     * Dodatečné přiznání: rozdíl podaných hodnot proti předchozímu podání.
     * Sjednocení řádků obou podání — řádek, který v novém výpočtu vypadl,
     * musí v rozdílu vyjít záporně, jinak by dodatečné přiznání tvrdilo,
     * že se nezměnil.
     *
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $filed
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $previousFiled
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    public static function vatReturnDiff(array $filed, array $previousFiled): array
    {
        $rows = array_unique(array_merge(array_keys($filed), array_keys($previousFiled)));
        sort($rows);

        $diff = [];
        foreach ($rows as $row) {
            $diff[$row] = self::subtractRows(
                $filed[$row] ?? self::EMPTY_ROW,
                $previousFiled[$row] ?? self::EMPTY_ROW,
            );
        }

        // Dodatečné přiznání nevykazuje vlastní daň ani nadměrný odpočet —
        // vykazuje jen změnu daňové povinnosti na ř. 66.
        $diff[64] = self::EMPTY_ROW;
        $diff[65] = self::EMPTY_ROW;
        $diff[66] = self::EMPTY_ROW;
        $diff[66]['taxFull'] = self::round2(
            self::liability($filed) - self::liability($previousFiled),
        );

        ksort($diff);
        return $diff;
    }

    /** Podaná hodnota souhrnného hlášení — nahoru na jednotku (`ceil`). */
    public static function recapitulativeValueFiled(float $value, float $unit = self::UNIT_KORUNA): float
    {
        if ($unit <= 0.0) {
            return self::round2($value);
        }
        return self::round2(ceil(self::round2($value) / $unit) * $unit);
    }

    /** Daňová povinnost podání (62 − 63) — základ pro ř. 66. */
    private static function liability(array $filed): float
    {
        return self::round2(
            ($filed[62]['taxFull'] ?? 0.0) - ($filed[63]['taxFull'] ?? 0.0),
        );
    }

    /**
     * @param array<string, float> $values
     * @return array{base: float, taxFull: float, taxReduced: float}
     */
    private static function normalizeRow(array $values): array
    {
        return [
            'base'       => self::round2((float) ($values['base'] ?? 0.0)),
            'taxFull'    => self::round2((float) ($values['taxFull'] ?? 0.0)),
            'taxReduced' => self::round2((float) ($values['taxReduced'] ?? 0.0)),
        ];
    }

    /**
     * @param array{base: float, taxFull: float, taxReduced: float} $row
     * @return array{base: float, taxFull: float, taxReduced: float}
     */
    private static function quantizeRow(array $row, float $unit): array
    {
        return array_map(static fn (float $value): float => self::quantize($value, $unit), $row);
    }

    /** Hodnota na násobek jednotky; jednotka 0,01 = zaokrouhlení na haléře. */
    private static function quantize(float $value, float $unit): float
    {
        if ($unit <= 0.0) {
            return self::round2($value);
        }
        return self::round2(round(self::round2($value) / $unit) * $unit);
    }

    /**
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $rows
     * @param list<int> $rowNumbers
     * @return array{base: float, taxFull: float, taxReduced: float}
     */
    private static function sumRows(array $rows, array $rowNumbers): array
    {
        $sum = self::EMPTY_ROW;
        foreach ($rowNumbers as $number) {
            foreach ($rows[$number] ?? [] as $field => $value) {
                $sum[$field] = self::round2($sum[$field] + $value);
            }
        }
        return $sum;
    }

    /**
     * @param array{base: float, taxFull: float, taxReduced: float} $a
     * @param array{base: float, taxFull: float, taxReduced: float} $b
     * @return array{base: float, taxFull: float, taxReduced: float}
     */
    private static function subtractRows(array $a, array $b): array
    {
        foreach ($b as $field => $value) {
            $a[$field] = self::round2($a[$field] - $value);
        }
        return $a;
    }

    private static function round2(float $value): float
    {
        return round($value, 2);
    }
}
