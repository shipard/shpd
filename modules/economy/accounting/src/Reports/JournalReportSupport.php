<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Reports;

use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;

/**
 * Sdílené helpery report builderů nad deníkem (`economy_accounting_journal`):
 * agregace per fiskální měsíce, názvy z účtového rozvrhu a chybové zprávy
 * pro `is_error` řádky. Buildery ho skládají (kompozice, D1) — hlavní kniha,
 * výsledovka i rozvaha agregují stejný zdroj, liší se jen výběrem tříd
 * a interpretací sloupců.
 */
final class JournalReportSupport
{
    private const JOURNAL_TABLE  = 'economy_accounting_journal';
    private const ACCOUNTS_TABLE = 'economy_accounting_accounts';
    private const DOC_STATE_DELETED = 90;

    /**
     * Agregace deníku přes FK id fiskálních měsíců (už scoped na fiskální rok
     * — id jsou globálně unikátní). Volitelný filtr tříd = seznam povolených
     * prvních znaků čísla účtu; platí i pro chybové masky (maska `5?????`
     * patří do výsledovky stejně jako regulérní účet třídy 5).
     *
     * @param list<int> $monthIds
     * @param list<string>|null $classFirstChars
     * @return list<array{number: string, isError: bool, md: float, d: float}>
     */
    public function aggregate(ReportRequest $request, array $monthIds, ?array $classFirstChars = null): array
    {
        if ($monthIds === []) {
            return [];
        }
        $sql = 'SELECT [account_number], [is_error],'
            . ' SUM([money_dr]) AS [md], SUM([money_cr]) AS [d]'
            . ' FROM [' . self::JOURNAL_TABLE . ']'
            . ' WHERE [fiscal_month] IN %in';
        $args = [$monthIds];
        if ($classFirstChars !== null) {
            $sql .= ' AND LEFT([account_number], 1) IN %in';
            $args[] = $classFirstChars;
        }
        $sql .= ' GROUP BY [account_number], [is_error]';

        $rows = $request->db->fetchAll($sql, ...$args);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'number'  => (string) $row['account_number'],
                'isError' => (bool) $row['is_error'],
                'md'      => (float) $row['md'],
                'd'       => (float) $row['d'],
            ];
        }
        return $out;
    }

    /**
     * Názvy z účtového rozvrhu pro detaily i mezisoučty (rozvrh obsahuje
     * i třídy, skupiny a syntetiky). Nenalezený název → label = číslo účtu.
     *
     * @return array<string, string> number → name
     */
    public function loadAccountNames(ReportRequest $request): array
    {
        $rows = $request->db->fetchAll(
            'SELECT [number], [name] FROM [' . self::ACCOUNTS_TABLE . ']'
            . ' WHERE [docState] != %i',
            self::DOC_STATE_DELETED,
        );
        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['number']] = (string) $row['name'];
        }
        return $names;
    }

    /**
     * @param list<string> $errorKeys Chybové masky (`account_number` u is_error řádků).
     * @param list<ReportRow> $rows Finální řádky (kvůli rowRef indexům).
     * @return list<ReportMessage>
     */
    public function errorMessages(array $errorKeys, array $rows, bool $cs): array
    {
        if ($errorKeys === []) {
            return [];
        }
        $indexByAccount = [];
        foreach ($rows as $index => $row) {
            if ($row->kind === ReportRowKind::Detail && $row->account !== null) {
                $indexByAccount[$row->account] = $index;
            }
        }

        $messages = [];
        sort($errorKeys, SORT_STRING);
        foreach ($errorKeys as $mask) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Error,
                'journal.accountNotFound',
                $cs
                    ? "Nedohledaný účet — v deníku zbyla chybová maska '{$mask}'"
                    : "Account not found — journal contains error mask '{$mask}'",
                isset($indexByAccount[$mask]) ? 'rows.' . $indexByAccount[$mask] : null,
            );
        }
        return $messages;
    }
}
