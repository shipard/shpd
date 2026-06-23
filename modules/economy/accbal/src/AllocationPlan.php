<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Výstup AllocationPlanneru: buď plán alokace platby na předpisy, nebo skip
 * s důvodem. Čistě hodnotový objekt — žádná DB, žádné `id` allocationu (ten
 * vznikne až zápisem).
 *
 * `items` je list položek {request_entry, amount, amount_hc} v měně dokladu
 * i domácí; jejich součet sedí přesně na částku platby v obou měnách (haléřové
 * dorovnání na poslední položce, docs/accbal.md §5.3).
 */
final class AllocationPlan
{
    public const SKIP_NO_OPEN_REQUESTS = 'no_open_requests';
    public const SKIP_ZERO_PAYMENT     = 'zero_payment';
    public const SKIP_OVERPAYMENT      = 'overpayment';

    /**
     * @param list<array{request_entry: int, amount: float, amount_hc: float}> $items
     */
    private function __construct(
        public readonly bool $matched,
        public readonly ?string $skipReason,
        public readonly array $items,
    ) {}

    /**
     * @param list<array{request_entry: int, amount: float, amount_hc: float}> $items
     */
    public static function matched(array $items): self
    {
        return new self(true, null, $items);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason, []);
    }
}
