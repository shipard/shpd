<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import\Parsers;

use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\ParsedStatement;
use Shipard\Module\Economy\Bank\Import\ParsedTransaction;
use Shipard\Module\Economy\Bank\Import\StatementParser;

/**
 * GPC (ABO fixní-šířkový) — port `cz/gpc/import.php`.
 *
 * Záznamy oddělené koncem řádku: 074 hlavička / 075 řádek / 078,079 memo
 * continuation. Offsety jsou ZNAKOVÉ (mb_substr). GPC nemá stabilní ID →
 * `externalId = null` (dedup přes fingerprint). Měnu nenese → null (z účtu).
 *
 * Pozn.: starý parser koncový zůstatek NEČETL; doplněn na standardní ABO
 * pozici (digits 60/14, znaménko 74) symetricky k počátečnímu (45/14, 59).
 */
final class GpcParser implements StatementParser
{
    public function parse(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $statements = [];
        $cur = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $type = mb_substr($line, 0, 3, 'UTF-8');
            if ($type === '074') {
                if ($cur !== null) {
                    $statements[] = $this->build($cur);
                }
                $cur = ['line' => $line, 'rows' => []];
            } elseif ($type === '075') {
                if ($cur !== null) {
                    $cur['rows'][] = $this->parseRow($line);
                }
            } elseif ($type === '078' || $type === '079') {
                if ($cur !== null && $cur['rows'] !== []) {
                    $cur['rows'][array_key_last($cur['rows'])]['memo'][] = ParserUtils::sub($line, 3);
                }
            }
        }
        if ($cur !== null) {
            $statements[] = $this->build($cur);
        }
        return $statements;
    }

    /** @param array{line: string, rows: array<int, array<string, mixed>>} $cur */
    private function build(array $cur): ParsedStatement
    {
        $h = $cur['line'];
        $periodStart = ParserUtils::parseDate('dmy', mb_substr($h, 39, 6, 'UTF-8'));
        $periodEnd = ParserUtils::parseDate('dmy', mb_substr($h, 108, 6, 'UTF-8'));
        if ($periodStart === null || $periodEnd === null) {
            throw new ImportException('GPC: neplatné datum období ve záhlaví 074.');
        }

        $transactions = [];
        foreach ($cur['rows'] as $r) {
            $transactions[] = new ParsedTransaction(
                externalId: null,
                amount: $r['amount'],
                dateTransaction: $r['date'],
                dateValue: null,
                counterpartyAccount: ParserUtils::nullIfEmpty($r['account']),
                counterpartyName: null,
                symbol1: $r['symbol1'],
                symbol2: $r['symbol2'],
                symbol3: $r['symbol3'],
                message: ParserUtils::mergeMemo($r['memo']),
                raw: $r,
            );
        }

        return new ParsedStatement(
            bankAccountRef: ParserUtils::decodeAboAccount(ParserUtils::sub($h, 3, 16)),
            statementNumber: ParserUtils::nullIfEmpty(mb_substr($h, 105, 3, 'UTF-8')),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalance: $this->signedAmount($h, 45, 59),
            closingBalance: $this->signedAmount($h, 60, 74),
            currency: null,
            transactions: $transactions,
        );
    }

    /** @return array<string, mixed> */
    private function parseRow(string $line): array
    {
        $account = ParserUtils::decodeAboAccount(ParserUtils::sub($line, 19, 16));
        $bankId = ParserUtils::sub($line, 73, 4);
        if ($account !== '' && $bankId !== '') {
            $account .= '/' . $bankId;
        }

        $amount = (int) mb_substr($line, 48, 12, 'UTF-8') / 100.0;
        $type = mb_substr($line, 60, 1, 'UTF-8');
        if ($type === '1' || $type === '5') {
            $amount = -$amount;
        }

        $date = ParserUtils::parseDate('dmy', mb_substr($line, 122, 6, 'UTF-8'));
        if ($date === null) {
            throw new ImportException('GPC: neplatné datum transakce v řádku 075.');
        }

        return [
            'account'  => $account,
            'amount'   => $amount,
            'date'     => $date,
            'symbol1'  => ParserUtils::normalizeSymbol(ParserUtils::sub($line, 61, 10)),
            'symbol2'  => ParserUtils::normalizeSymbol(ParserUtils::sub($line, 81, 10)),
            'symbol3'  => ParserUtils::normalizeSymbol(ParserUtils::sub($line, 77, 4)),
            'memo'     => [ParserUtils::sub($line, 97, 20)],
        ];
    }

    /** Znaménková částka v haléřích: 14 číslic od $digitsFrom, znaménko na $signPos ('-' = záporné). */
    private function signedAmount(string $line, int $digitsFrom, int $signPos): float
    {
        $value = (int) mb_substr($line, $digitsFrom, 14, 'UTF-8') / 100.0;
        return mb_substr($line, $signPos, 1, 'UTF-8') === '-' ? -$value : $value;
    }
}
