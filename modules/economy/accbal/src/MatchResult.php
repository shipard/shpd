<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Výsledek pokusu o spárování jedné platby. Nese dost informací pro čitelný
 * report ({@see AccbalMatchCommand}) i pro agregaci do {@see MatchSummary}.
 */
final class MatchResult
{
    /** Platba spárována — vznikla 311/321 úhrada + allocations. */
    public const STATUS_ALLOCATED = 'allocated';
    /** Platba přesměrována (dry-run by alokoval), reálně nic nezměněno. */
    public const STATUS_PLANNED = 'planned';
    /** Platba neprošla (zůstává na clearingu / beze změny) — viz reason. */
    public const STATUS_SKIPPED = 'skipped';
    /** Reaccount proběhl, ale 311/321 úhrada se nenašla → alokace přeskočena. */
    public const STATUS_ROUTED_UNALLOCATED = 'routed_unallocated';

    /**
     * @param list<array{request_entry: int, amount: float, amount_hc: float}> $items
     */
    private function __construct(
        public readonly int $txId,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?string $targetCode,
        public readonly ?int $partner,
        public readonly ?string $currency,
        public readonly float $paymentAmount,
        public readonly array $items,
    ) {}

    /**
     * @param list<array{request_entry: int, amount: float, amount_hc: float}> $items
     */
    public static function allocated(int $txId, string $targetCode, ?int $partner, ?string $currency, float $paymentAmount, array $items): self
    {
        return new self($txId, self::STATUS_ALLOCATED, null, $targetCode, $partner, $currency, $paymentAmount, $items);
    }

    /**
     * @param list<array{request_entry: int, amount: float, amount_hc: float}> $items
     */
    public static function planned(int $txId, string $targetCode, ?int $partner, ?string $currency, float $paymentAmount, array $items): self
    {
        return new self($txId, self::STATUS_PLANNED, null, $targetCode, $partner, $currency, $paymentAmount, $items);
    }

    public static function skipped(int $txId, string $reason, float $paymentAmount = 0.0): self
    {
        return new self($txId, self::STATUS_SKIPPED, $reason, null, null, null, $paymentAmount, []);
    }

    public static function routedUnallocated(int $txId, string $targetCode, ?int $partner, ?string $currency, float $paymentAmount): self
    {
        return new self($txId, self::STATUS_ROUTED_UNALLOCATED, 'payment_move_missing', $targetCode, $partner, $currency, $paymentAmount, []);
    }
}
