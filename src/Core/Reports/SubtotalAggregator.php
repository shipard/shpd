<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Obecný prefix-based rollup detail řádků (klíč = číslo účtu): pro zadané
 * délky prefixů (v1: 3 = syntetika, 2 = skupina, 1 = třída) vyrobí subtotal
 * řádky + závěrečný total. Sčítá `md`/`d` per sloupec; `balance` = md − d
 * (znaménko dle stran účtu, ne prezentace — D6).
 *
 * Výstupní pořadí: detail řádky dle čísla účtu, subtotal následuje hned za
 * svou skupinou (delší prefix před kratším), total na konci. Levely:
 * total = 0, subtotaly 1..k dle délky prefixu (nejkratší = 1); level detail
 * řádků určuje builder.
 *
 * Píše se obecně — Fáze 2 (výsledovka, rozvaha) ho použije beze změn.
 */
final class SubtotalAggregator
{
    /**
     * @param list<ReportRow> $detailRows Řádky s neprázdným `account`.
     * @param list<int> $prefixLengths Délky prefixů, např. [3, 2, 1].
     * @param callable(string, int): string $labelResolver
     *        fn(prefix, délka) → label subtotal řádku.
     * @return list<ReportRow>
     */
    public function rollup(
        array $detailRows,
        array $prefixLengths,
        callable $labelResolver,
        string $totalLabel,
    ): array {
        if ($detailRows === []) {
            return [];
        }

        usort(
            $detailRows,
            static fn (ReportRow $a, ReportRow $b): int => strcmp((string) $a->account, (string) $b->account),
        );

        $lengthsDesc = $prefixLengths;
        rsort($lengthsDesc, SORT_NUMERIC);
        $lengthsAsc = array_reverse($lengthsDesc);

        // Nejkratší prefix (třída) = level 1, delší postupně hlouběji.
        $levelByLength = [];
        foreach ($lengthsAsc as $i => $length) {
            $levelByLength[$length] = $i + 1;
        }

        /** @var array<int, array{prefix: string, sums: array<string, array{md: float, d: float}>}> $open */
        $open  = [];
        $total = [];
        $out   = [];

        foreach ($detailRows as $row) {
            $account = (string) $row->account;

            foreach ($lengthsDesc as $length) {
                $prefix = substr($account, 0, $length);
                if (isset($open[$length]) && $open[$length]['prefix'] !== $prefix) {
                    $out[] = $this->makeRow(
                        ReportRowKind::Subtotal,
                        $levelByLength[$length],
                        $open[$length]['prefix'],
                        $labelResolver($open[$length]['prefix'], $length),
                        $open[$length]['sums'],
                    );
                    unset($open[$length]);
                }
                if (!isset($open[$length])) {
                    $open[$length] = ['prefix' => $prefix, 'sums' => []];
                }
                $this->accumulate($open[$length]['sums'], $row->values);
            }

            $this->accumulate($total, $row->values);
            $out[] = $row;
        }

        foreach ($lengthsDesc as $length) {
            if (!isset($open[$length])) {
                continue;
            }
            $out[] = $this->makeRow(
                ReportRowKind::Subtotal,
                $levelByLength[$length],
                $open[$length]['prefix'],
                $labelResolver($open[$length]['prefix'], $length),
                $open[$length]['sums'],
            );
        }

        $out[] = $this->makeRow(ReportRowKind::Total, 0, null, $totalLabel, $total);

        return $out;
    }

    /**
     * @param array<string, array{md: float, d: float}> $sums
     * @param array<string, array{md: float, d: float, balance: float}> $values
     */
    private function accumulate(array &$sums, array $values): void
    {
        foreach ($values as $columnId => $cell) {
            if (!is_array($cell)) {
                continue; // text/date buňky (string) nelze agregovat
            }
            if (!isset($sums[$columnId])) {
                $sums[$columnId] = ['md' => 0.0, 'd' => 0.0];
            }
            $sums[$columnId]['md'] += $cell['md'];
            $sums[$columnId]['d']  += $cell['d'];
        }
    }

    /** @param array<string, array{md: float, d: float}> $sums */
    private function makeRow(
        ReportRowKind $kind,
        int $level,
        ?string $account,
        string $label,
        array $sums,
    ): ReportRow {
        $values = [];
        foreach ($sums as $columnId => $sum) {
            $md = round($sum['md'], 2);
            $d  = round($sum['d'], 2);
            $values[$columnId] = ['md' => $md, 'd' => $d, 'balance' => round($md - $d, 2)];
        }
        return new ReportRow($kind, $level, $account, $label, $values);
    }
}
