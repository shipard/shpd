<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import\Parsers;

use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\ParsedStatement;
use Shipard\Module\Economy\Bank\Import\ParsedTransaction;
use Shipard\Module\Economy\Bank\Import\StatementParser;

/**
 * CAMT / ISO 20022 (`cba-xml`) — port `cz/cba-xml/import.php` s doplněním
 * toho, co starý parser zahazoval: měna, `BookgDt` (datum zaúčtování),
 * `externalId` (AcctSvcrRef/NtryRef) a jméno protistrany.
 *
 * Multi-statement (`Stmt[]`). XML se čte přes SimpleXML; default namespace se
 * neutralizuje, aby šel přístup po lokálních jménech (funguje pro reálné
 * namespaced i syntetické soubory). Víceúrovňové přístupy jdou přes path()
 * (null-safe).
 */
final class CbaXmlParser implements StatementParser
{
    private const OPENING_CODES = ['OPBD', 'PRCD', 'ITBD'];
    private const CLOSING_CODES = ['CLBD', 'CLAV', 'CLAVL'];

    public function parse(string $text): array
    {
        $clean = (string) preg_replace('/\sxmlns="[^"]*"/', '', $text);
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($clean);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new ImportException('CAMT: vstup není validní XML.');
        }
        if (!isset($xml->BkToCstmrStmt->Stmt)) {
            throw new ImportException('CAMT: chybí element BkToCstmrStmt/Stmt.');
        }

        $statements = [];
        foreach ($xml->BkToCstmrStmt->Stmt as $stmt) {
            $statements[] = $this->parseStmt($stmt);
        }
        return $statements;
    }

    private function parseStmt(\SimpleXMLElement $stmt): ParsedStatement
    {
        $account = $this->pathStr($stmt, 'Acct', 'Id', 'IBAN')
            ?? $this->pathStr($stmt, 'Acct', 'Id', 'Othr', 'Id');
        if ($account === null) {
            throw new ImportException('CAMT: chybí číslo účtu (Stmt/Acct/Id).');
        }

        $periodStart = $this->date($this->pathStr($stmt, 'FrToDt', 'FrDtTm') ?? $this->pathStr($stmt, 'FrToDt', 'FrDt'));
        $periodEnd = $this->date($this->pathStr($stmt, 'FrToDt', 'ToDtTm') ?? $this->pathStr($stmt, 'FrToDt', 'ToDt'));
        if ($periodStart === null || $periodEnd === null) {
            throw new ImportException('CAMT: chybí nebo neplatné období (Stmt/FrToDt).');
        }

        [$opening, $closing, $balCcy] = $this->balances($stmt);

        $transactions = [];
        foreach ($stmt->Ntry as $ntry) {
            $transactions[] = $this->parseEntry($ntry);
        }

        return new ParsedStatement(
            bankAccountRef: $account,
            statementNumber: $this->pathStr($stmt, 'LglSeqNb')
                ?? $this->pathStr($stmt, 'ElctrncSeqNb')
                ?? $this->pathStr($stmt, 'Id'),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalance: $opening,
            closingBalance: $closing,
            currency: $this->pathStr($stmt, 'Acct', 'Ccy') ?? $balCcy,
            transactions: $transactions,
        );
    }

    /** @return array{0: float, 1: float, 2: ?string} [opening, closing, currency] */
    private function balances(\SimpleXMLElement $stmt): array
    {
        $opening = null;
        $closing = null;
        $ccy = null;
        $ordered = [];

        foreach ($stmt->Bal as $bal) {
            $amount = ParserUtils::parseAmount($this->pathStr($bal, 'Amt') ?? '0');
            if ($this->pathStr($bal, 'CdtDbtInd') === 'DBIT') {
                $amount = -$amount;
            }
            if ($ccy === null && isset($bal->Amt)) {
                $ccy = $this->str($bal->Amt['Ccy']);
            }
            $code = $this->pathStr($bal, 'Tp', 'CdOrPrtry', 'Cd');
            $ordered[] = $amount;
            if ($code !== null && in_array($code, self::OPENING_CODES, true)) {
                $opening = $amount;
            } elseif ($code !== null && in_array($code, self::CLOSING_CODES, true)) {
                $closing = $amount;
            }
        }

        // Fallback dle pořadí, když chybí typové kódy (jako starý parser).
        if ($opening === null && $ordered !== []) {
            $opening = $ordered[0];
        }
        if ($closing === null && count($ordered) >= 2) {
            $closing = $ordered[count($ordered) - 1];
        }

        return [$opening ?? 0.0, $closing ?? 0.0, $ccy];
    }

    private function parseEntry(\SimpleXMLElement $ntry): ParsedTransaction
    {
        $cdtDbt = $this->pathStr($ntry, 'CdtDbtInd');
        $amount = ParserUtils::parseAmount($this->pathStr($ntry, 'Amt') ?? '0');
        if ($cdtDbt === 'DBIT') {
            $amount = -$amount;
        }

        $dateTransaction = $this->date($this->pathStr($ntry, 'BookgDt', 'Dt') ?? $this->pathStr($ntry, 'BookgDt', 'DtTm'))
            ?? $this->date($this->pathStr($ntry, 'ValDt', 'Dt') ?? $this->pathStr($ntry, 'ValDt', 'DtTm'));
        if ($dateTransaction === null) {
            throw new ImportException('CAMT: chybí datum zaúčtování i valuty (Ntry/BookgDt|ValDt).');
        }
        $dateValue = $this->date($this->pathStr($ntry, 'ValDt', 'Dt') ?? $this->pathStr($ntry, 'ValDt', 'DtTm'));

        $externalId = $this->pathStr($ntry, 'AcctSvcrRef') ?? $this->pathStr($ntry, 'NtryRef');

        $txDtls = isset($ntry->NtryDtls->TxDtls) ? $ntry->NtryDtls->TxDtls[0] : null;
        [$s1, $s2, $s3] = $txDtls !== null ? $this->symbols($txDtls) : [null, null, null];
        [$cpAccount, $cpName] = $txDtls !== null ? $this->counterparty($txDtls, $cdtDbt === 'CRDT') : [null, null];
        $memo = $txDtls !== null ? $this->memo($txDtls) : null;

        if ($externalId === null && $txDtls !== null) {
            $externalId = $this->pathStr($txDtls, 'Refs', 'AcctSvcrRef');
        }

        return new ParsedTransaction(
            externalId: $externalId,
            amount: $amount,
            dateTransaction: $dateTransaction,
            dateValue: $dateValue,
            counterpartyAccount: $cpAccount,
            counterpartyName: $cpName,
            symbol1: $s1,
            symbol2: $s2,
            symbol3: $s3,
            message: $memo,
        );
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} [VS, SS, KS] */
    private function symbols(\SimpleXMLElement $txDtls): array
    {
        $s1 = $s2 = $s3 = null;

        // Strukturované reference RmtInf/Strd: "VS:123" / "SS:..." / "KS:..."
        if (isset($txDtls->RmtInf->Strd)) {
            foreach ($txDtls->RmtInf->Strd as $strd) {
                $ref = $this->pathStr($strd, 'CdtrRefInf', 'Ref') ?? $this->pathStr($strd, 'Ref');
                if ($ref === null || !str_contains($ref, ':')) {
                    continue;
                }
                [$key, $val] = explode(':', $ref, 2);
                match ($key) {
                    'VS' => $s1 = ParserUtils::normalizeSymbol($val),
                    'SS' => $s2 = ParserUtils::normalizeSymbol($val),
                    'KS' => $s3 = ParserUtils::normalizeSymbol($val),
                    default => null,
                };
            }
        }

        // Fallback z Refs: EndToEndId → VS, PmtInfId → SS/KS dle prefixu.
        $e2e = $this->pathStr($txDtls, 'Refs', 'EndToEndId');
        if ($s1 === null && $e2e !== null && $e2e !== 'NOTPROVIDED') {
            $s1 = ParserUtils::normalizeSymbol(str_starts_with($e2e, 'VS') ? substr($e2e, 2) : $e2e);
        }
        $pmt = $this->pathStr($txDtls, 'Refs', 'PmtInfId');
        if ($pmt !== null) {
            if ($s2 === null && str_starts_with($pmt, 'SS')) {
                $s2 = ParserUtils::normalizeSymbol(substr($pmt, 2));
            } elseif ($s3 === null && str_starts_with($pmt, 'KS')) {
                $s3 = ParserUtils::normalizeSymbol(substr($pmt, 2));
            }
        }

        return [$s1, $s2, $s3];
    }

    /** @return array{0: ?string, 1: ?string} [counterpartyAccount, counterpartyName] */
    private function counterparty(\SimpleXMLElement $txDtls, bool $incoming): array
    {
        $party = $incoming ? 'Dbtr' : 'Cdtr';
        $acctKey = $party . 'Acct';

        $account = $this->pathStr($txDtls, 'RltdPties', $acctKey, 'Id', 'IBAN')
            ?? $this->pathStr($txDtls, 'RltdPties', $acctKey, 'Id', 'Othr', 'Id');

        // Domácí číslo + kód banky z RltdAgts.
        if ($account !== null && !str_contains($account, '/')) {
            $bank = $this->pathStr($txDtls, 'RltdAgts', $party . 'Agt', 'FinInstnId', 'Othr', 'Id');
            if ($bank !== null) {
                $account .= '/' . $bank;
            }
        }

        return [$account, $this->pathStr($txDtls, 'RltdPties', $party, 'Nm')];
    }

    private function memo(\SimpleXMLElement $txDtls): ?string
    {
        $lines = [];
        if (isset($txDtls->RmtInf->Ustrd)) {
            foreach ($txDtls->RmtInf->Ustrd as $u) {
                $lines[] = $this->str($u);
            }
        }
        $lines[] = $this->pathStr($txDtls, 'AddtlTxInf');
        return ParserUtils::mergeMemo($lines);
    }

    /** Null-safe průchod víceúrovňovou cestou SimpleXML elementů. */
    private function path(\SimpleXMLElement $node, string ...$names): ?\SimpleXMLElement
    {
        $cur = $node;
        foreach ($names as $name) {
            if (!isset($cur->$name)) {
                return null;
            }
            $cur = $cur->$name;
        }
        return $cur;
    }

    private function pathStr(\SimpleXMLElement $node, string ...$names): ?string
    {
        $el = $this->path($node, ...$names);
        return $el === null ? null : $this->str($el);
    }

    private function str(mixed $node): ?string
    {
        if ($node === null) {
            return null;
        }
        $s = trim((string) $node);
        return $s === '' ? null : $s;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
