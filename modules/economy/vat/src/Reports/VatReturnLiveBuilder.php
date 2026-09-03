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
use Shipard\Module\Economy\Vat\VatJournalCrossCheck;
use Shipard\Module\Economy\Vat\VatReturnCalculator;

/**
 * Živé přiznání k DPH (DPHDP3) — počítá se on-demand z potvrzených dokladů
 * (D1, žádná persistence). Řádky formuláře s daty + dopočty 46/62–65
 * (kind computed); hlavička výsledku nese operativní stav DPH (ř. 64/65)
 * a výsledek křížové kontroly proti 343 analytikám deníku jako messages.
 */
final class VatReturnLiveBuilder implements ReportBuilder
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
        $calc = (new VatReturnCalculator($mapping))->calculate($docs);

        $rows     = [];
        $messages = [];
        if ($docs !== []) {
            $all = $calc['rows'];
            foreach ($calc['computed'] as $number => $values) {
                $all[$number] = $values;
            }
            ksort($all);
            $computedNumbers = array_keys($calc['computed']);

            foreach ($all as $number => $values) {
                $label = $mapping->dp3RowLabel((int) $number)
                    ?? ($cs ? "Řádek {$number}" : "Row {$number}");
                $rows[] = new ReportRow(
                    in_array($number, $computedNumbers, true) ? ReportRowKind::Computed : ReportRowKind::Detail,
                    0,
                    (string) $number,
                    $label,
                    [
                        'base'       => $support->money($values['base']),
                        'tax'        => $support->money($values['taxFull']),
                        'taxReduced' => $support->money($values['taxReduced']),
                    ],
                );
            }

            $messages[] = $this->currentPositionMessage($calc['computed'], $cs);
            array_push($messages, ...$this->crossCheckMessages($request, $support, $docs, $cs));
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

    /** Operativní stav DPH (ř. 64/65) — informační řádek hlavičky (D1). */
    private function currentPositionMessage(array $computed, bool $cs): ReportMessage
    {
        $own    = $computed[64]['taxFull'];
        $excess = $computed[65]['taxFull'];
        if ($own > 0) {
            $text = ($cs ? 'Vlastní daň (ř. 64): ' : 'Tax liability (row 64): ')
                . number_format($own, 2, ',', ' ');
        } elseif ($excess > 0) {
            $text = ($cs ? 'Nadměrný odpočet (ř. 65): ' : 'Excess deduction (row 65): ')
                . number_format($excess, 2, ',', ' ');
        } else {
            $text = $cs ? 'Daňová povinnost je nulová.' : 'The tax position is zero.';
        }
        return new ReportMessage(ReportMessageSeverity::Info, 'vatReturn.currentPosition', $text);
    }

    /**
     * Křížová kontrola proti deníku — dvě nezávislé cesty ke stejným
     * číslům (D1); rozdíly jsou warning, shoda info.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<ReportMessage>
     */
    private function crossCheckMessages(ReportRequest $request, VatReportSupport $support, array $docs, bool $cs): array
    {
        $check    = (new VatJournalCrossCheck($request->db, $support->vatCodes($request)))->check($docs);
        $messages = [];
        foreach ($check['differences'] as $diff) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'vatReturn.journalMismatch',
                sprintf(
                    $cs
                        ? 'Křížová kontrola: účet %s (%s) — daň dle dokladů %s, deník %s, rozdíl %s.'
                        : 'Cross-check: account %s (%s) — tax per documents %s, journal %s, delta %s.',
                    $diff['account'],
                    $diff['vatCode'] ?? '—',
                    number_format($diff['recapTax'], 2, ',', ' '),
                    number_format($diff['journalTax'], 2, ',', ' '),
                    number_format($diff['delta'], 2, ',', ' '),
                ),
            );
        }
        if ($check['journalErrorRows'] > 0) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'vatReturn.journalErrorRows',
                $cs
                    ? "Deník obsahuje {$check['journalErrorRows']} chybových 343 řádků za výběr — křížová kontrola je nespolehlivá."
                    : "The journal contains {$check['journalErrorRows']} error rows on 343 accounts for the selection — the cross-check is unreliable.",
            );
        }
        if ($check['differences'] === [] && $check['journalErrorRows'] === 0) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Info,
                'vatReturn.journalMatch',
                $cs ? 'Součty daně souhlasí s účetním deníkem.' : 'Tax totals match the accounting journal.',
            );
        }
        return $messages;
    }

    /** @return list<ReportColumn> */
    private function columns(bool $cs): array
    {
        return [
            new ReportColumn('base', ReportColumn::TYPE_MONEY, $cs ? 'Základ daně / Hodnota' : 'Tax base / Value'),
            new ReportColumn('tax', ReportColumn::TYPE_MONEY, $cs ? 'Daň' : 'Tax'),
            new ReportColumn('taxReduced', ReportColumn::TYPE_MONEY, $cs ? 'Krácený odpočet' : 'Reduced deduction'),
        ];
    }
}
