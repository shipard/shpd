<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Strojové porovnání dvou dekódovaných `ReportResult` array (D14) — čistá
 * třída bez DB, primární využití: kontrola importu ze starého Shipardu
 * (`report-diff` CLI). Strany nemusejí mít shodné `reportId` ani stejnou
 * sadu sloupců — porovnává se průnik sloupců dle `id`.
 *
 * Porovnávají se pouze řádky `kind: detail` (klíč = `account`) a jako
 * kontrolní součet řádky `kind: total` (klíč = `label`, jen labely přítomné
 * na obou stranách — stará strana total mít nemusí). Subtotaly a computed
 * se ignorují — derivují z detailů.
 *
 * D15: vstup se `status: errors` porovnání nezastaví, ale výsledek nese
 * `statusA`/`statusB` — striktní odmítnutí je věc konzumenta (CLI `--strict`).
 */
final class ReportDiff
{
    /** Tolerance absolutního rozdílu hodnot — pod ní se strany berou jako shodné. */
    public const TOLERANCE = 0.005;

    private const FIELDS = ['md', 'd', 'balance'];

    /**
     * @param array<string, mixed> $a Dekódovaný `ReportResult::toArray()` strany A.
     * @param array<string, mixed> $b Dekódovaný `ReportResult::toArray()` strany B.
     * @return array{
     *     identical: bool,
     *     differences: list<array{account: string, column: string, field: string, a: float, b: float, delta: float}>,
     *     onlyInA: list<string>,
     *     onlyInB: list<string>,
     *     columnsOnlyInA: list<string>,
     *     columnsOnlyInB: list<string>,
     *     statusA: string,
     *     statusB: string,
     * }
     */
    public function diff(array $a, array $b): array
    {
        $columnsA = $this->columnIds($a);
        $columnsB = $this->columnIds($b);
        $sharedColumns = array_values(array_intersect($columnsA, $columnsB));

        $detailsA = $this->rowsByKey($a, 'detail', 'account');
        $detailsB = $this->rowsByKey($b, 'detail', 'account');
        $totalsA  = $this->rowsByKey($a, 'total', 'label');
        $totalsB  = $this->rowsByKey($b, 'total', 'label');

        // Číselné účty PHP kastuje na int klíče — ven jdou vždy stringy.
        $onlyInA = array_map(strval(...), array_values(array_diff(array_keys($detailsA), array_keys($detailsB))));
        $onlyInB = array_map(strval(...), array_values(array_diff(array_keys($detailsB), array_keys($detailsA))));

        $differences = [];
        foreach ($detailsA as $account => $valuesA) {
            if (!isset($detailsB[$account])) {
                continue;
            }
            $this->compareValues((string) $account, $valuesA, $detailsB[$account], $sharedColumns, $differences);
        }
        // Totaly jen jako kontrolní součet — label chybějící na jedné straně
        // není rozdíl (total je volitelný), porovnává se jen průnik labelů.
        foreach ($totalsA as $label => $valuesA) {
            if (!isset($totalsB[$label])) {
                continue;
            }
            $this->compareValues((string) $label, $valuesA, $totalsB[$label], $sharedColumns, $differences);
        }

        return [
            'identical'      => $differences === [] && $onlyInA === [] && $onlyInB === [],
            'differences'    => $differences,
            'onlyInA'        => $onlyInA,
            'onlyInB'        => $onlyInB,
            'columnsOnlyInA' => array_values(array_diff($columnsA, $columnsB)),
            'columnsOnlyInB' => array_values(array_diff($columnsB, $columnsA)),
            'statusA'        => (string) ($a['status'] ?? 'ok'),
            'statusB'        => (string) ($b['status'] ?? 'ok'),
        ];
    }

    /** @return list<string> */
    private function columnIds(array $result): array
    {
        $ids = [];
        foreach ($result['columns'] ?? [] as $column) {
            if (is_array($column) && isset($column['id']) && is_string($column['id'])) {
                $ids[] = $column['id'];
            }
        }
        return $ids;
    }

    /**
     * Řádky daného kind klíčované hodnotou $keyField; řádky bez klíče se
     * přeskočí (nelze je spárovat).
     *
     * @return array<string, array<string, mixed>> klíč => values
     */
    private function rowsByKey(array $result, string $kind, string $keyField): array
    {
        $out = [];
        foreach ($result['rows'] ?? [] as $row) {
            if (!is_array($row) || ($row['kind'] ?? null) !== $kind) {
                continue;
            }
            $key = $row[$keyField] ?? null;
            if (!is_string($key) || $key === '') {
                continue;
            }
            $out[$key] = is_array($row['values'] ?? null) ? $row['values'] : [];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $valuesA
     * @param array<string, mixed> $valuesB
     * @param list<string> $columns
     * @param list<array<string, mixed>> $differences
     */
    private function compareValues(string $key, array $valuesA, array $valuesB, array $columns, array &$differences): void
    {
        foreach ($columns as $column) {
            foreach (self::FIELDS as $field) {
                $valA = (float) ($valuesA[$column][$field] ?? 0.0);
                $valB = (float) ($valuesB[$column][$field] ?? 0.0);
                if (abs($valB - $valA) <= self::TOLERANCE) {
                    continue;
                }
                $differences[] = [
                    'account' => $key,
                    'column'  => $column,
                    'field'   => $field,
                    'a'       => $valA,
                    'b'       => $valB,
                    'delta'   => round($valB - $valA, 6),
                ];
            }
        }
    }
}
