<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Reports;

use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\SubtotalAggregator;

/**
 * Rozvaha (interní podoba) — stavy tříd 0–4 dělené na Aktiva/Pasiva:
 *
 * - `opening`: stav k začátku intervalu (vše před ním vč. otevíracích
 *   dokladů), `closing` = opening + obraty intervalu,
 * - zařazení do sekce per analytický účet: `account_kind` 0 → Aktiva,
 *   1 → Pasiva; kind 5 (aktivně pasivní), NULL či jiný → dle znaménka
 *   closing balance (≥ 0 Aktiva) — zjednodušení v1, opening strana se
 *   může lišit (otevřený bod pro Fázi 2.1),
 * - výsledek hospodaření běžného období = `computed` řádek v pasivech
 *   (D13, vzorec výsledovky nad třídami 5/6), vstupuje do PASIVA CELKEM.
 *
 * V pasivní sekci se otáčí znaménko `balance` (md/d zůstávají syrové) —
 * builder tím definuje sémantiku sloupce své sekce, renderer nic
 * nedopočítává; není to porušení D6.
 *
 * Vestavěné invarianty (D15): AKTIVA CELKEM == PASIVA CELKEM a vyrovnanost
 * deníku (Σ balance tříd 0–4 + Σ balance tříd 5–6 == 0). Porušení →
 * ReportMessage severity error, report se přesto vrátí — právě kvůli
 * odhalení nevyrovnaných deníků existuje.
 */
final class BalanceSheetBuilder implements ReportBuilder
{
    private const ACCOUNTS_TABLE = 'economy_accounting_accounts';
    private const DOC_STATE_DELETED = 90;

    private const BS_CLASSES = ['0', '1', '2', '3', '4'];
    private const PL_CLASSES = ['5', '6'];

    private const KIND_ASSETS      = 0;
    private const KIND_LIABILITIES = 1;

    private const COLUMNS = ['opening', 'closing'];

    public function build(ReportRequest $request): ReportResult
    {
        $cs        = $request->language === 'cs';
        $synthetic = ($request->params['detail'] ?? 'analytic') === 'synthetic';
        $support   = new JournalReportSupport();

        $before  = $support->aggregate($request, $request->range->monthIdsBefore, self::BS_CLASSES);
        $inRange = $support->aggregate($request, $request->range->monthIdsInRange, self::BS_CLASSES);

        // Sloučení per analytický účet (masky zvlášť): opening = vše před
        // intervalem, closing = opening + obraty intervalu.
        $accounts = [];
        foreach (['opening' => $before, 'closing' => $inRange] as $columnId => $sums) {
            foreach ($sums as $sum) {
                $key = $sum['number'];
                if (!isset($accounts[$key])) {
                    $accounts[$key] = [
                        'isError' => $sum['isError'],
                        'opening' => ['md' => 0.0, 'd' => 0.0],
                        'closing' => ['md' => 0.0, 'd' => 0.0],
                    ];
                }
                $accounts[$key][$columnId]['md'] += $sum['md'];
                $accounts[$key][$columnId]['d']  += $sum['d'];
                if ($columnId === 'opening') {
                    $accounts[$key]['closing']['md'] += $sum['md'];
                    $accounts[$key]['closing']['d']  += $sum['d'];
                }
            }
        }

        $names       = $support->loadAccountNames($request);
        $kinds       = $this->loadAccountKinds($request);
        $detailLevel = $synthetic ? 3 : 4;

        // Rozdělení do sekcí + syrové sumy tříd 0–4 pro invarianty.
        $sections  = ['assets' => [], 'liabilities' => []];
        $bsSum     = ['opening' => 0.0, 'closing' => 0.0];
        $errorKeys = [];
        $wrongSide = [];
        foreach ($accounts as $number => $acc) {
            $number = (string) $number;
            $o = ['md' => round($acc['opening']['md'], 2), 'd' => round($acc['opening']['d'], 2)];
            $c = ['md' => round($acc['closing']['md'], 2), 'd' => round($acc['closing']['d'], 2)];
            // Účet bez pohybu v roce do rozvahy nepatří.
            if ($o['md'] === 0.0 && $o['d'] === 0.0 && $c['md'] === 0.0 && $c['d'] === 0.0) {
                continue;
            }
            $closingBalance = round($c['md'] - $c['d'], 2);
            $bsSum['opening'] += $o['md'] - $o['d'];
            $bsSum['closing'] += $closingBalance;

            $kind    = $acc['isError'] ? null : ($kinds[$number] ?? null);
            $section = match ($kind) {
                self::KIND_ASSETS      => 'assets',
                self::KIND_LIABILITIES => 'liabilities',
                // Kind 5 (aktivně pasivní), NULL či jiný: dle znaménka
                // closing balance — zjednodušení v1, viz doc komentář třídy.
                default => $closingBalance >= 0.0 ? 'assets' : 'liabilities',
            };
            // Účet s explicitní povahou (kind 0/1) a konečným zůstatkem na
            // opačné straně — typicky aktivně pasivní účet, který v rozvrhu
            // není označen kind 5. Zařazení respektuje rozvrh (sekce se
            // nemění); warning na rozpor upozorní (D15). Pozn.: u účtů
            // třídy 4 (např. 429, 431) může být protistranný zůstatek
            // legitimní (ztráta) — warning je informativní, ne chyba.
            if (!$acc['isError'] && (
                ($kind === self::KIND_ASSETS && $closingBalance < -0.005)
                || ($kind === self::KIND_LIABILITIES && $closingBalance > 0.005)
            )) {
                $wrongSide[] = $number;
            }

            $sections[$section][$number] = [
                'isError' => $acc['isError'],
                'opening' => $o,
                'closing' => $c,
            ];
        }

        // Výsledek hospodaření tříd 5/6: opening = k začátku intervalu,
        // closing = kumulativně do konce intervalu (vzorec výsledovky ytd).
        $plSum = ['opening' => 0.0, 'closing' => 0.0];
        foreach ($support->aggregate($request, $request->range->monthIdsBefore, self::PL_CLASSES) as $sum) {
            $plSum['opening'] += $sum['md'] - $sum['d'];
            $plSum['closing'] += $sum['md'] - $sum['d'];
        }
        foreach ($support->aggregate($request, $request->range->monthIdsInRange, self::PL_CLASSES) as $sum) {
            $plSum['closing'] += $sum['md'] - $sum['d'];
        }
        $profit = [
            'opening' => round(-$plSum['opening'], 2),
            'closing' => round(-$plSum['closing'], 2),
        ];

        $aggregator = new SubtotalAggregator();
        $rows       = [];

        // Sekce Aktiva.
        $assetsRows  = $this->sectionRollup($sections['assets'], $synthetic, $names, $aggregator, $errorKeys);
        $assetsTotal = $this->relabelTotal(array_pop($assetsRows), $cs ? 'AKTIVA CELKEM' : 'TOTAL ASSETS');
        array_push($rows, ...$assetsRows);
        $rows[] = $assetsTotal;

        // Sekce Pasiva — otočené znaménko balance, computed výsledek před totalem.
        $liabRows     = $this->sectionRollup($sections['liabilities'], $synthetic, $names, $aggregator, $errorKeys);
        $liabTotalRaw = array_pop($liabRows);
        foreach ($liabRows as $row) {
            $rows[] = $this->flipBalance($row);
        }
        $rows[] = new ReportRow(
            ReportRowKind::Computed,
            $detailLevel,
            null,
            $cs ? 'Výsledek hospodaření běžného období' : 'Profit or loss of the current period',
            [
                'opening' => ['md' => 0.0, 'd' => 0.0, 'balance' => $profit['opening']],
                'closing' => ['md' => 0.0, 'd' => 0.0, 'balance' => $profit['closing']],
            ],
        );
        $liabTotalValues = [];
        foreach (self::COLUMNS as $columnId) {
            $cell = $liabTotalRaw->values[$columnId];
            $liabTotalValues[$columnId] = [
                'md'      => $cell['md'],
                'd'       => $cell['d'],
                'balance' => round(-$cell['balance'] + $profit[$columnId], 2),
            ];
        }
        $liabTotal = new ReportRow(
            ReportRowKind::Total,
            0,
            null,
            $cs ? 'PASIVA CELKEM' : 'TOTAL LIABILITIES & EQUITY',
            $liabTotalValues,
        );
        $rows[] = $liabTotal;

        $messages = $support->errorMessages($errorKeys, $rows, $cs);
        foreach ($wrongSide as $number) {
            $accName    = $names[$number] ?? $number;
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'balanceSheet.balanceOnWrongSide',
                $cs
                    ? "Účet {$number} ({$accName}) má konečný zůstatek na opačné straně, než odpovídá jeho povaze v účtovém rozvrhu — zvažte označení účtu jako aktivně pasivní."
                    : "Account {$number} ({$accName}) has a closing balance on the opposite side than its chart kind suggests — consider marking the account as mixed (assets & liabilities).",
                $number,
            );
        }
        foreach (self::COLUMNS as $columnId) {
            $diff = round($assetsTotal->values[$columnId]['balance'] - $liabTotal->values[$columnId]['balance'], 2);
            if (abs($diff) > 0.005) {
                $messages[] = new ReportMessage(
                    ReportMessageSeverity::Error,
                    'balanceSheet.notBalanced',
                    $cs
                        ? "Rozvaha nevyrovnaná — sloupec '{$columnId}': AKTIVA CELKEM − PASIVA CELKEM = " . sprintf('%.2f', $diff)
                        : "Balance sheet not balanced — column '{$columnId}': total assets − total liabilities = " . sprintf('%.2f', $diff),
                );
            }
            $imbalance = round($bsSum[$columnId] + $plSum[$columnId], 2);
            if (abs($imbalance) > 0.005) {
                $messages[] = new ReportMessage(
                    ReportMessageSeverity::Error,
                    'balanceSheet.journalImbalance',
                    $cs
                        ? "Nevyrovnaný deník — sloupec '{$columnId}': Σ zůstatků tříd 0–6 = " . sprintf('%.2f', $imbalance)
                        : "Journal imbalance — column '{$columnId}': sum of class 0–6 balances = " . sprintf('%.2f', $imbalance),
                );
            }
        }

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $messages,
            columns: [
                new ReportColumn('opening', ReportColumn::TYPE_MONEY, $cs ? 'Počáteční stav' : 'Opening balance'),
                new ReportColumn('closing', ReportColumn::TYPE_MONEY, $cs ? 'Konečný zůstatek' : 'Closing balance'),
            ],
            rows: $rows,
        );
    }

    /**
     * Detail řádky + rollup jedné sekce. Poslední řádek výstupu je vždy
     * Total (aggregator ho vrací nepodmíněně; u prázdné sekce se vyrobí
     * nulový — invarianty a struktura sekcí ho potřebují vždy).
     *
     * @param array<string, array{isError: bool, opening: array{md: float, d: float}, closing: array{md: float, d: float}}> $sectionAccounts
     * @param array<string, string> $names
     * @param list<string> $errorKeys
     * @return list<ReportRow> Neprázdný, poslední = Total.
     */
    private function sectionRollup(
        array $sectionAccounts,
        bool $synthetic,
        array $names,
        SubtotalAggregator $aggregator,
        array &$errorKeys,
    ): array {
        // Syntetické slučování až uvnitř sekce — analytiky téhož syntetického
        // účtu (kind 5) mohou skončit v opačných sekcích. Masky se neslučují.
        $merged = [];
        foreach ($sectionAccounts as $number => $acc) {
            $key = (!$acc['isError'] && $synthetic) ? substr((string) $number, 0, 3) : (string) $number;
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'isError' => $acc['isError'],
                    'opening' => ['md' => 0.0, 'd' => 0.0],
                    'closing' => ['md' => 0.0, 'd' => 0.0],
                ];
            }
            foreach (self::COLUMNS as $columnId) {
                $merged[$key][$columnId]['md'] += $acc[$columnId]['md'];
                $merged[$key][$columnId]['d']  += $acc[$columnId]['d'];
            }
        }

        $detailLevel = $synthetic ? 3 : 4;
        $detailRows  = [];
        foreach ($merged as $key => $acc) {
            $key = (string) $key;
            if ($acc['isError']) {
                $errorKeys[] = $key;
            }
            $values = [];
            foreach (self::COLUMNS as $columnId) {
                $md = round($acc[$columnId]['md'], 2);
                $d  = round($acc[$columnId]['d'], 2);
                $values[$columnId] = ['md' => $md, 'd' => $d, 'balance' => round($md - $d, 2)];
            }
            $detailRows[] = new ReportRow(
                ReportRowKind::Detail,
                $detailLevel,
                $key,
                $acc['isError'] ? $key : ($names[$key] ?? $key),
                $values,
            );
        }

        $rows = $aggregator->rollup(
            $detailRows,
            $synthetic ? [2, 1] : [3, 2, 1],
            static fn (string $prefix, int $length): string => $names[$prefix] ?? $prefix,
            'Total',
        );
        if ($rows === []) {
            $zero = ['md' => 0.0, 'd' => 0.0, 'balance' => 0.0];
            $rows = [new ReportRow(ReportRowKind::Total, 0, null, 'Total', [
                'opening' => $zero,
                'closing' => $zero,
            ])];
        }
        return $rows;
    }

    private function relabelTotal(ReportRow $total, string $label): ReportRow
    {
        return new ReportRow($total->kind, $total->level, $total->account, $label, $total->values);
    }

    /** Otočení znaménka balance (prezentace pasivní sekce), md/d syrové. */
    private function flipBalance(ReportRow $row): ReportRow
    {
        $values = [];
        foreach ($row->values as $columnId => $cell) {
            $values[$columnId] = [
                'md'      => $cell['md'],
                'd'       => $cell['d'],
                'balance' => $cell['balance'] === 0.0 ? 0.0 : -$cell['balance'],
            ];
        }
        return new ReportRow($row->kind, $row->level, $row->account, $row->label, $values);
    }

    /**
     * Povaha účtu z rozvrhu — jen pro zařazení analytik do sekcí
     * (na skupinových řádcích rozvrhu je kind nespolehlivý).
     *
     * @return array<string, int> number → account_kind
     */
    private function loadAccountKinds(ReportRequest $request): array
    {
        $rows = $request->db->fetchAll(
            'SELECT [number], [account_kind] FROM [' . self::ACCOUNTS_TABLE . ']'
            . ' WHERE [docState] != %i AND [account_kind] IS NOT NULL',
            self::DOC_STATE_DELETED,
        );
        $kinds = [];
        foreach ($rows as $row) {
            $kinds[(string) $row['number']] = (int) $row['account_kind'];
        }
        return $kinds;
    }
}
