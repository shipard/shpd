<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Interval fiskálních měsíců uvnitř jednoho fiskálního roku — jediný tvar
 * období, který report engine zná (D8). Vzniká výhradně
 * v `ReportParamValidator`, který přeloží request parametry na FK id
 * fiskálních měsíců, aby buildery nemusely znovu sahat na číselníky.
 *
 * `monthFrom`/`monthTo` jsou pořadí běžného fiskálního měsíce v roce
 * (1-based dle `date_begin`; u kalendářního roku shodné s kalendářním
 * měsícem). Otevírací období (period_type 0) není adresovatelné intervalem —
 * patří vždy do `monthIdsBefore` (počáteční stavy, D3).
 */
final class FiscalRange
{
    /**
     * @param string $fiscalYear Název fiskálního roku (např. "2026").
     * @param list<int> $monthIdsBefore FK id měsíců před intervalem
     *                                  (vč. otevíracího období).
     * @param list<int> $monthIdsInRange FK id běžných měsíců intervalu.
     */
    public function __construct(
        public readonly int $fiscalYearId,
        public readonly string $fiscalYear,
        public readonly int $monthFrom,
        public readonly int $monthTo,
        public readonly array $monthIdsBefore,
        public readonly array $monthIdsInRange,
    ) {}

    /** Tvar klíče `period` v `ReportResult::params`. @return array<string, mixed> */
    public function toParamsArray(): array
    {
        return [
            'fiscalYear' => $this->fiscalYear,
            'monthFrom'  => $this->monthFrom,
            'monthTo'    => $this->monthTo,
        ];
    }
}
