<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Reports;

use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Module\Economy\Vat\EcSalesListCalculator;

/**
 * Živé souhrnné hlášení (DPHSHV) — agregace per (kód plnění, DIČ
 * odběratele): počet plnění + hodnota v domácí měně (plná přesnost,
 * zaokrouhlení nahoru až věc XML). Chybějící DIČ = warning message.
 */
final class VatEcSalesLiveBuilder implements ReportBuilder
{
    public function build(ReportRequest $request): ReportResult
    {
        $cs      = $request->language === 'cs';
        $support = new VatReportSupport();
        $columns = $this->columns($cs);

        $mapping = $support->mapping($request);
        if ($mapping === null) {
            return $support->missingConfigResult($request, $columns, $cs);
        }

        $docs = $support->docs($request);
        $calc = (new EcSalesListCalculator($mapping))->calculate($docs);

        $rows       = [];
        $totalCount = 0;
        $totalValue = 0.0;
        foreach ($calc['rows'] as $row) {
            $rows[] = new ReportRow(ReportRowKind::Detail, 0, null, '', [
                'vatId' => $row['vatId'],
                'kod'   => (string) $row['kod'],
                'count' => (string) $row['count'],
                'value' => $support->money($row['value']),
            ]);
            $totalCount += $row['count'];
            $totalValue += $row['value'];
        }
        if ($rows !== []) {
            $rows[] = new ReportRow(ReportRowKind::Total, 0, null, $cs ? 'Celkem' : 'Total', [
                'count' => (string) $totalCount,
                'value' => $support->money($totalValue),
            ]);
        }

        $messages = [];
        foreach ($calc['errors'] as $error) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'vatSh.missingVatId',
                sprintf(
                    $cs
                        ? 'Doklad %s: chybí DIČ odběratele — plnění nelze vykázat v souhrnném hlášení.'
                        : 'Document %s: missing customer VAT ID — the supply cannot be reported in the EC sales list.',
                    $error['docNumber'],
                ),
            );
        }

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $messages,
            columns: $columns,
            rows: $rows,
        );
    }

    /** @return list<ReportColumn> */
    private function columns(bool $cs): array
    {
        return [
            new ReportColumn('vatId', ReportColumn::TYPE_TEXT, $cs ? 'DIČ odběratele' : 'Customer VAT ID'),
            new ReportColumn('kod', ReportColumn::TYPE_TEXT, $cs ? 'Kód plnění' : 'Supply code'),
            new ReportColumn('count', ReportColumn::TYPE_TEXT, $cs ? 'Počet plnění' : 'Supply count'),
            new ReportColumn('value', ReportColumn::TYPE_MONEY, $cs ? 'Hodnota plnění' : 'Supply value'),
        ];
    }
}
