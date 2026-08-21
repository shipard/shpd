<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Reports;

use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\SubtotalAggregator;

/**
 * Hlavní kniha — čistá agregace deníku (`economy_accounting_journal`, D3):
 *
 * - `opening`: suma za měsíce roku před intervalem (otevírací doklady 701
 *   jsou v deníku jako každé jiné účtování, nic se nedopočítává),
 * - `turnover`: suma za interval,
 * - `closing` = opening + turnover (per strany, balance = md − d).
 *
 * `detail = synthetic` agreguje na 3místný prefix. Řádky `is_error = 1`
 * (nedohledaný účet) se nezahazují — vstoupí jako samostatný detail řádek
 * s chybovou maskou a vygenerují zprávu `journal.accountNotFound` (D15).
 * Účty bez pohybu v roce se neemitují.
 */
final class GeneralLedgerBuilder implements ReportBuilder
{
    public function build(ReportRequest $request): ReportResult
    {
        $cs        = $request->language === 'cs';
        $synthetic = ($request->params['detail'] ?? 'analytic') === 'synthetic';
        $support   = new JournalReportSupport();

        $opening  = $support->aggregate($request, $request->range->monthIdsBefore);
        $turnover = $support->aggregate($request, $request->range->monthIdsInRange);

        // Sloučení na klíč řádku: analyticky plné číslo účtu, synteticky
        // 3místný prefix. Chybové masky se neagregují na prefix — zůstávají
        // samostatným řádkem i v syntetickém režimu.
        $accounts = [];
        foreach (['opening' => $opening, 'turnover' => $turnover] as $columnId => $sums) {
            foreach ($sums as $sum) {
                $key = (!$sum['isError'] && $synthetic)
                    ? substr($sum['number'], 0, 3)
                    : $sum['number'];
                if (!isset($accounts[$key])) {
                    $accounts[$key] = [
                        'isError'  => $sum['isError'],
                        'opening'  => ['md' => 0.0, 'd' => 0.0],
                        'turnover' => ['md' => 0.0, 'd' => 0.0],
                    ];
                }
                $accounts[$key][$columnId]['md'] += $sum['md'];
                $accounts[$key][$columnId]['d']  += $sum['d'];
            }
        }

        $names       = $support->loadAccountNames($request);
        $detailLevel = $synthetic ? 3 : 4;

        $detailRows = [];
        $errorKeys  = [];
        foreach ($accounts as $key => $acc) {
            $key = (string) $key;
            $o   = ['md' => round($acc['opening']['md'], 2), 'd' => round($acc['opening']['d'], 2)];
            $t   = ['md' => round($acc['turnover']['md'], 2), 'd' => round($acc['turnover']['d'], 2)];
            // Účet bez pohybu v roce do knihy nepatří.
            if ($o['md'] === 0.0 && $o['d'] === 0.0 && $t['md'] === 0.0 && $t['d'] === 0.0) {
                continue;
            }
            $c = ['md' => round($o['md'] + $t['md'], 2), 'd' => round($o['d'] + $t['d'], 2)];

            if ($acc['isError']) {
                $errorKeys[] = $key;
            }
            $detailRows[] = new ReportRow(
                ReportRowKind::Detail,
                $detailLevel,
                $key,
                $acc['isError'] ? $key : ($names[$key] ?? $key),
                [
                    'opening'  => $o + ['balance' => round($o['md'] - $o['d'], 2)],
                    'turnover' => $t + ['balance' => round($t['md'] - $t['d'], 2)],
                    'closing'  => $c + ['balance' => round($c['md'] - $c['d'], 2)],
                ],
            );
        }

        $aggregator = new SubtotalAggregator();
        $rows       = $aggregator->rollup(
            $detailRows,
            $synthetic ? [2, 1] : [3, 2, 1],
            static fn (string $prefix, int $length): string => $names[$prefix] ?? $prefix,
            $cs ? 'Celkem' : 'Total',
        );

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $support->errorMessages($errorKeys, $rows, $cs),
            columns: [
                new ReportColumn('opening', ReportColumn::TYPE_MONEY, $cs ? 'Počáteční stav' : 'Opening balance'),
                new ReportColumn('turnover', ReportColumn::TYPE_MONEY, $cs ? 'Obraty za období' : 'Period turnover', ReportColumn::DISPLAY_SIDES),
                new ReportColumn('closing', ReportColumn::TYPE_MONEY, $cs ? 'Konečný zůstatek' : 'Closing balance'),
            ],
            rows: $rows,
        );
    }
}
