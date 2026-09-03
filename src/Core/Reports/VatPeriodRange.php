<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Instance daňového tvrzení jako období reportu — druhý tvar období vedle
 * `FiscalRange` (reporty s `periodSource: 'vatPeriod'`). Vzniká výhradně
 * v `ReportParamValidator` z parametru `period` (id instance
 * `economy_vat_report_periods`), typ instance musí odpovídat deklaraci
 * `vatReportType` reportu (D11). Registrace je z instance odvozená.
 *
 * Výběr dokladů jde přes materializovaný ukazatel na hlavičce
 * (`vat_period` / `cs_period` / `rs_period`), nikdy přes datum přímo (D8).
 * Zámek (`locked`) tu záměrně není vynucený — Fáze 4; katalog ho nese
 * jen jako UI informaci.
 */
final class VatPeriodRange
{
    /**
     * @param string $dateBegin ISO `YYYY-MM-DD`
     * @param string $dateEnd ISO `YYYY-MM-DD`
     */
    public function __construct(
        public readonly int $periodId,
        public readonly string $reportType,
        public readonly string $periodName,
        public readonly int $registrationId,
        public readonly string $registrationName,
        public readonly string $dateBegin,
        public readonly string $dateEnd,
        public readonly bool $locked = false,
    ) {}

    /** Tvar klíče `period` v `ReportResult::params`. @return array<string, mixed> */
    public function toParamsArray(): array
    {
        return [
            'period'          => $this->periodId,
            'reportType'      => $this->reportType,
            'name'            => $this->periodName,
            'vatRegistration' => $this->registrationId,
            'dateFrom'        => $this->dateBegin,
            'dateTo'          => $this->dateEnd,
        ];
    }
}
