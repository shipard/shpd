<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Agregát dávkového běhu matcheru. Sbírá jednotlivé {@see MatchResult} a drží
 * souhrnné počty (spárováno / přeskočeno dle důvodu) i Σ spárované částky.
 */
final class MatchSummary
{
    /** @var list<MatchResult> */
    public array $results = [];

    public int $allocated = 0;
    public int $planned = 0;
    public int $routedUnallocated = 0;

    /** @var array<string, int> reason → count */
    public array $skipped = [];

    public float $matchedAmount = 0.0;

    public function add(MatchResult $r): void
    {
        $this->results[] = $r;
        switch ($r->status) {
            case MatchResult::STATUS_ALLOCATED:
                $this->allocated++;
                $this->matchedAmount = round($this->matchedAmount + $r->paymentAmount, 2);
                break;
            case MatchResult::STATUS_PLANNED:
                $this->planned++;
                $this->matchedAmount = round($this->matchedAmount + $r->paymentAmount, 2);
                break;
            case MatchResult::STATUS_ROUTED_UNALLOCATED:
                $this->routedUnallocated++;
                break;
            case MatchResult::STATUS_SKIPPED:
                $reason = $r->reason ?? 'unknown';
                $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
                break;
        }
    }

    public function candidates(): int
    {
        return count($this->results);
    }
}
