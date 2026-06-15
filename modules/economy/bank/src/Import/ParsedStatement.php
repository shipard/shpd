<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

/**
 * Neutrální mezistruktura jednoho bankovního výpisu — výstup parseru.
 * Jeden soubor může nést více výpisů (CAMT `Stmt[]`, GPC více `074`).
 *
 * `bankAccountRef` je identifikátor NAŠEHO účtu z výpisu (IBAN / domácí číslo
 * / accountId); import service ho zmatchuje na `economy_codebooks_bank_accounts`.
 * `currency` je null tam, kde formát měnu nenese (GPC/FIO) — doplní se z účtu.
 */
final class ParsedStatement
{
    /** @param ParsedTransaction[] $transactions */
    public function __construct(
        public readonly string $bankAccountRef,
        public readonly ?string $statementNumber,
        public readonly \DateTimeImmutable $periodStart,
        public readonly \DateTimeImmutable $periodEnd,
        public readonly float $openingBalance,
        public readonly float $closingBalance,
        public readonly ?string $currency = null,
        public readonly array $transactions = [],
    ) {}
}
