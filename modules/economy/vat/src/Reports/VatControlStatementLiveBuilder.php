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
use Shipard\Module\Economy\Vat\ControlStatementCalculator;

/**
 * Živé kontrolní hlášení (DPHKH1) — sekce jako bloky v plochém seznamu:
 * hlavičkový subtotal řádek se součty pásem, pod ním detailní řádky per
 * doklad (ev. číslo / DIČ / kód PDP / DPPD v text/date sloupcích);
 * A5/B3 jen agregátní součtový řádek. Měkké chyby kalkulátoru se mapují
 * na warning messages s rowRef na dotčený řádek.
 */
final class VatControlStatementLiveBuilder implements ReportBuilder
{
    /** Pořadí a titulky sekcí. */
    private const SECTION_TITLES = [
        'A1' => [
            'cs' => 'A1 — Uskutečněná plnění v režimu přenesení daňové povinnosti',
            'en' => 'A1 — Supplies under the domestic reverse charge (supplier)',
        ],
        'A2' => [
            'cs' => 'A2 — Přijatá přeshraniční plnění s povinností přiznat daň',
            'en' => 'A2 — Received cross-border supplies with self-assessed tax',
        ],
        'A4' => [
            'cs' => 'A4 — Uskutečněná zdanitelná plnění nad 10 000 Kč',
            'en' => 'A4 — Taxable supplies above CZK 10,000',
        ],
        'A5' => [
            'cs' => 'A5 — Ostatní uskutečněná zdanitelná plnění (do 10 000 Kč)',
            'en' => 'A5 — Other taxable supplies (up to CZK 10,000, aggregate)',
        ],
        'B1' => [
            'cs' => 'B1 — Přijatá plnění v režimu přenesení daňové povinnosti',
            'en' => 'B1 — Received supplies under the domestic reverse charge',
        ],
        'B2' => [
            'cs' => 'B2 — Přijatá zdanitelná plnění nad 10 000 Kč',
            'en' => 'B2 — Received taxable supplies above CZK 10,000',
        ],
        'B3' => [
            'cs' => 'B3 — Ostatní přijatá zdanitelná plnění (do 10 000 Kč)',
            'en' => 'B3 — Other received taxable supplies (up to CZK 10,000, aggregate)',
        ],
    ];

    private const BAND_KEYS = ['base1', 'tax1', 'base2', 'tax2', 'base3', 'tax3'];

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
        $calc = (new ControlStatementCalculator($mapping, $support->vatCodes($request)))
            ->calculate($docs);

        $rows              = [];
        $rowIndexByDocRef  = [];
        foreach (self::SECTION_TITLES as $section => $titles) {
            $sectionRows = $calc['sections'][$section] ?? [];
            if ($sectionRows === []) {
                continue;
            }

            $sums = array_fill_keys(self::BAND_KEYS, 0.0);
            foreach ($sectionRows as $sectionRow) {
                foreach (self::BAND_KEYS as $key) {
                    $sums[$key] += $sectionRow[$key];
                }
            }
            $rows[] = new ReportRow(
                ReportRowKind::Subtotal,
                0,
                $section,
                $titles[$cs ? 'cs' : 'en'],
                $this->bandValues($support, $sums),
            );

            if ($section === 'A5' || $section === 'B3') {
                continue; // agregát = jen součtový řádek
            }
            foreach ($sectionRows as $sectionRow) {
                $rowIndexByDocRef["{$section}|{$sectionRow['docId']}"] = count($rows);
                $rows[] = new ReportRow(
                    ReportRowKind::Detail,
                    1,
                    null,
                    '',
                    [
                        'evidNumber' => (string) $sectionRow['evidNumber'],
                        'vatId'      => (string) $sectionRow['vatId'],
                        'kodPredPl'  => $sectionRow['kodPredPl'] !== null ? (string) $sectionRow['kodPredPl'] : '',
                        'dppd'       => (string) ($sectionRow['dppd'] ?? ''),
                    ] + $this->bandValues($support, $sectionRow),
                );
            }
        }

        $messages = [];
        foreach ($calc['errors'] as $error) {
            $index      = $rowIndexByDocRef["{$error['section']}|{$error['docId']}"] ?? null;
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'vatKh.' . $error['code'],
                sprintf(
                    $error['code'] === 'missingVatId'
                        ? ($cs ? 'Doklad %s (sekce %s): chybí DIČ partnera.' : 'Document %s (section %s): missing partner VAT ID.')
                        : ($cs ? 'Doklad %s (sekce %s): chybí číslo dokladu dodavatele (ev. číslo).' : 'Document %s (section %s): missing supplier document number (evidence number).'),
                    $error['docNumber'],
                    $error['section'],
                ),
                $index !== null ? "rows.{$index}" : null,
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

    /**
     * @param array<string, mixed> $bands Zdroj s klíči base1..tax3.
     * @return array<string, array{md: float, d: float, balance: float}>
     */
    private function bandValues(VatReportSupport $support, array $bands): array
    {
        $values = [];
        foreach (self::BAND_KEYS as $key) {
            $values[$key] = $support->money((float) $bands[$key]);
        }
        return $values;
    }

    /** @return list<ReportColumn> */
    private function columns(bool $cs): array
    {
        return [
            new ReportColumn('evidNumber', ReportColumn::TYPE_TEXT, $cs ? 'Ev. číslo dokladu' : 'Evidence number'),
            new ReportColumn('vatId', ReportColumn::TYPE_TEXT, $cs ? 'DIČ' : 'VAT ID'),
            new ReportColumn('kodPredPl', ReportColumn::TYPE_TEXT, $cs ? 'Kód' : 'Code'),
            new ReportColumn('dppd', ReportColumn::TYPE_DATE, 'DPPD'),
            new ReportColumn('base1', ReportColumn::TYPE_MONEY, $cs ? 'Základ — základní' : 'Base — standard'),
            new ReportColumn('tax1', ReportColumn::TYPE_MONEY, $cs ? 'Daň — základní' : 'Tax — standard'),
            new ReportColumn('base2', ReportColumn::TYPE_MONEY, $cs ? 'Základ — snížená' : 'Base — reduced'),
            new ReportColumn('tax2', ReportColumn::TYPE_MONEY, $cs ? 'Daň — snížená' : 'Tax — reduced'),
            new ReportColumn('base3', ReportColumn::TYPE_MONEY, $cs ? 'Základ — 2. snížená' : 'Base — 2nd reduced'),
            new ReportColumn('tax3', ReportColumn::TYPE_MONEY, $cs ? 'Daň — 2. snížená' : 'Tax — 2nd reduced'),
        ];
    }
}
