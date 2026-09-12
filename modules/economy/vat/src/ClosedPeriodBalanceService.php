<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Kontrola zůstatků 343 za podané instance přiznání (#55 D31) — čistá
 * třída s DB dotazy, sdílená alertem `economy.vat.closed_period_balance`,
 * varováním při zamykání fiskálního měsíce (FiscalMonthDocument) a detailem
 * instance (ReportPeriodsViewer).
 *
 * Pro instanci `return` s podaným podáním (docState 40):
 *
 *   docs = docs_core_heads.id WHERE docState != 90 AND (
 *            vat_period = instance
 *            OR id IN (economy_vat_filings.acc_document WHERE report_period = instance
 *                      AND docState = 40 AND acc_document IS NOT NULL))
 *   Σ per account_number LIKE '343%' AND account_number NOT IN (343801, 343802)
 *     (money_dr − money_cr) FROM economy_accounting_journal WHERE doc_head IN docs
 *   nenulové (|Σ| > 0.005) → nález {account, balance}
 *
 * Nulový součet = DPH období je „vypořádaná": analytiky 343 se po zaúčtování
 * přiznání (účetní doklad podání, F4b — do instance nespadá, drží ho FK
 * `acc_document`) vynulují proti saldu 343801 (odvod) / 343802 (odpočet).
 * Nenulový = chybí zaúčtování přiznání, doklad přiznání je ještě koncept,
 * nebo se DPH po podání změnila (→ dodatečné podání + zaúčtování).
 */
final class ClosedPeriodBalanceService
{
    public const TOLERANCE = 0.005;

    /** Saldo analytiky přiznání (odvod / odpočet) — do vypořádání se nepočítají. */
    public const EXCLUDED_ANALYTICS = ['343801', '343802'];

    public function __construct(private readonly DataSourceConnection $db) {}

    /**
     * Instance `return` s aspoň jedním podaným podáním, nejnovější první.
     * Volitelně jen jedna instance, nebo jen ty s koncem období v rozsahu.
     *
     * @return list<array{id: int, name: string, date_end: string, vat_registration: int}>
     */
    public function filedReturnPeriods(?int $periodId = null, ?string $endFrom = null, ?string $endTo = null): array
    {
        $sql = 'SELECT [p].[id], [p].[name], [p].[date_end], [p].[vat_registration]'
            . ' FROM [economy_vat_report_periods] [p]'
            . ' WHERE [p].[report_type] = %s AND [p].[docState] != 90'
            . ' AND EXISTS (SELECT 1 FROM [economy_vat_filings] [f]'
            . '   WHERE [f].[report_period] = [p].[id] AND [f].[docState] = %i)';
        $args = [VatPeriodAssigner::TYPE_RETURN, FilingDocument::DOC_STATE_FILED];
        if ($periodId !== null) {
            $sql .= ' AND [p].[id] = %i';
            $args[] = $periodId;
        }
        if ($endFrom !== null && $endTo !== null) {
            $sql .= ' AND [p].[date_end] >= %d AND [p].[date_end] <= %d';
            $args[] = $endFrom;
            $args[] = $endTo;
        }
        $sql .= ' ORDER BY [p].[date_end] DESC, [p].[id] DESC';

        $out = [];
        foreach ($this->db->fetchAll($sql, ...$args) as $row) {
            $out[] = [
                'id'               => (int) $row['id'],
                'name'             => (string) $row['name'],
                'date_end'         => (string) VatPeriodAssigner::isoDate($row['date_end']),
                'vat_registration' => (int) $row['vat_registration'],
            ];
        }
        return $out;
    }

    /**
     * Nenulové zůstatky 343 analytik (mimo 801/802) přes doklady instance
     * a účetní doklady jejích podaných podání.
     *
     * @return list<array{account: string, balance: float}>
     */
    public function balancesForPeriod(int $periodId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [j].[account_number], SUM([j].[money_dr]) - SUM([j].[money_cr]) AS [balance]'
            . ' FROM [economy_accounting_journal] [j]'
            . ' WHERE [j].[doc_head] IN (SELECT [h].[id] FROM [docs_core_heads] [h]'
            . '   WHERE [h].[docState] != 90 AND ([h].[vat_period] = %i'
            . '     OR [h].[id] IN (SELECT [f].[acc_document] FROM [economy_vat_filings] [f]'
            . '       WHERE [f].[report_period] = %i AND [f].[docState] = %i AND [f].[acc_document] IS NOT NULL)))'
            . ' AND [j].[account_number] LIKE %like~ AND [j].[account_number] NOT IN %in'
            . ' GROUP BY [j].[account_number]'
            . ' ORDER BY [j].[account_number]',
            $periodId, $periodId, FilingDocument::DOC_STATE_FILED, '343', self::EXCLUDED_ANALYTICS,
        );
        $out = [];
        foreach ($rows as $row) {
            $balance = round((float) $row['balance'], 2);
            if (abs($balance) <= self::TOLERANCE) {
                continue;
            }
            $out[] = ['account' => (string) $row['account_number'], 'balance' => $balance];
        }
        return $out;
    }

    /**
     * Nálezy per (instance, účet) přes podané instance — volitelně jen jednu.
     *
     * @return list<array{period_id: int, period_name: string, date_end: string, account: string, balance: float}>
     */
    public function findings(?int $periodId = null): array
    {
        return $this->collect($this->filedReturnPeriods($periodId));
    }

    /**
     * Nálezy pro podané instance končící v rozsahu (zamykání fiskálního měsíce).
     *
     * @return list<array{period_id: int, period_name: string, date_end: string, account: string, balance: float}>
     */
    public function findingsForRange(string $endFrom, string $endTo): array
    {
        return $this->collect($this->filedReturnPeriods(null, $endFrom, $endTo));
    }

    /**
     * @param list<array{id: int, name: string, date_end: string, vat_registration: int}> $periods
     * @return list<array{period_id: int, period_name: string, date_end: string, account: string, balance: float}>
     */
    private function collect(array $periods): array
    {
        $out = [];
        foreach ($periods as $period) {
            foreach ($this->balancesForPeriod($period['id']) as $balance) {
                $out[] = [
                    'period_id'   => $period['id'],
                    'period_name' => $period['name'],
                    'date_end'    => $period['date_end'],
                    'account'     => $balance['account'],
                    'balance'     => $balance['balance'],
                ];
            }
        }
        return $out;
    }

    /** „1 234,56" — text částky pro alerty a varování (Kč doplňuje volající). */
    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
