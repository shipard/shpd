<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Taxes\Reports;

use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Module\Economy\Taxes\VatDocumentSelection;
use Shipard\Module\Economy\Taxes\VatOutputsMapping;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Sdílené kusy tří živých DPH builderů — kompozice, žádná dědičnost
 * (vzor JournalReportSupport): načtení mapovací konfigurace, číselníku
 * kódů, výběru dokladů dle VatPeriodRange a degradace při chybějícím
 * kompilovaném configu.
 */
final class VatReportSupport
{
    public function mapping(ReportRequest $request): ?VatOutputsMapping
    {
        $cfg = $request->config?->cfgItem('economy.taxes.reports.cz');
        return is_array($cfg) ? new VatOutputsMapping($cfg) : null;
    }

    /** @return array<string, array<string, mixed>> Kódy vč. skrytých (párové). */
    public function vatCodes(ReportRequest $request): array
    {
        if ($request->config === null) {
            return [];
        }
        return (new VatRateResolver($request->config))->getVatCodes('cz', null, null, true);
    }

    /** @return list<array<string, mixed>> Doklady výběru dle VatPeriodRange. */
    public function docs(ReportRequest $request): array
    {
        $range = $request->vatRange;
        if ($range === null) {
            throw new \RuntimeException(
                "Report '{$request->reportId}': missing VatPeriodRange (declare periodSource 'vatPeriod')",
            );
        }
        return (new VatDocumentSelection($request->db))
            ->load($range->registrationId, $range->dateBegin, $range->dateEnd);
    }

    /**
     * Chybějící kompilovaný config = error výsledek, ne crash (vzor
     * degradace server-driven textů).
     *
     * @param list<\Shipard\Core\Reports\ReportColumn> $columns
     */
    public function missingConfigResult(ReportRequest $request, array $columns, bool $cs): ReportResult
    {
        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: [new ReportMessage(
                ReportMessageSeverity::Error,
                'vatReports.missingConfig',
                $cs
                    ? 'Chybí kompilovaná konfigurace mapování DPH výstupů (economy.taxes.reports.cz) — spusťte ds-upgrade.'
                    : 'Missing compiled VAT outputs mapping config (economy.taxes.reports.cz) — run ds-upgrade.',
            )],
            columns: $columns,
            rows: [],
        );
    }

    /** Money buňka jednohodnotového sloupce. @return array{md: float, d: float, balance: float} */
    public function money(float $value): array
    {
        return ['md' => 0.0, 'd' => 0.0, 'balance' => round($value, 2)];
    }
}
