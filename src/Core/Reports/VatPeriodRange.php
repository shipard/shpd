<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Interval období DPH jedné registrace — druhý tvar období vedle
 * `FiscalRange` (reporty s `periodSource: 'vatPeriod'`). Vzniká výhradně
 * v `ReportParamValidator`: interval `dateBegin`–`dateEnd` přesně souvisle
 * pokrývá ≥1 období z `economy_codebooks_vat_periods` (D5 — výběr dokladů
 * jde přes členství `vat_period` v intervalu, nikdy přes DUZP přímo).
 *
 * Zámek období (`locked`) tu záměrně není — vynucení je věc Fáze 4;
 * katalog ho nese jen jako UI informaci.
 */
final class VatPeriodRange
{
    /**
     * @param string $dateBegin ISO `YYYY-MM-DD` — začátek prvního období.
     * @param string $dateEnd ISO `YYYY-MM-DD` — konec posledního období.
     * @param list<int> $periodIds FK id pokrytých období (dle `date_begin`).
     * @param list<string> $periodNames Názvy pokrytých období (např. "01/2026").
     */
    public function __construct(
        public readonly int $registrationId,
        public readonly string $registrationName,
        public readonly string $dateBegin,
        public readonly string $dateEnd,
        public readonly array $periodIds,
        public readonly array $periodNames,
    ) {}

    /** Tvar klíče `period` v `ReportResult::params`. @return array<string, mixed> */
    public function toParamsArray(): array
    {
        return [
            'vatRegistration' => $this->registrationId,
            'dateFrom'        => $this->dateBegin,
            'dateTo'          => $this->dateEnd,
        ];
    }
}
