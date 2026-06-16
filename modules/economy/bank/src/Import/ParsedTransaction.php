<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

/**
 * Neutrální mezistruktura jedné bankovní transakce z výpisu — výstup parseru,
 * vstup import service. Immutable, bez vazby na DB.
 *
 * `amount` je ZNAMÉNKOVÁ (záporná = výdaj); import ji rozloží na kladný
 * `amount` + `direction`. `operation` je obvykle null (souborové parsery ho
 * nenesou → default dle směru payment.in/out); migrace přes výměnný formát
 * může předat explicitní pohyb. `raw` nese surový parser payload pro
 * fingerprint a debug. `exchangeRate` (měna transakce → domácí) nese migrace
 * u cizoměnových transakcí; null = 1 (domácí měna).
 */
final class ParsedTransaction
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly ?string $externalId,
        public readonly float $amount,
        public readonly \DateTimeImmutable $dateTransaction,
        public readonly ?\DateTimeImmutable $dateValue = null,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $symbol1 = null,
        public readonly ?string $symbol2 = null,
        public readonly ?string $symbol3 = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
        public readonly ?string $operation = null,
        public readonly ?float $exchangeRate = null,
    ) {}
}
