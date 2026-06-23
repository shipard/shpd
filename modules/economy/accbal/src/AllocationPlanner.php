<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Čisté jádro matcheru: rozhodne, jak rozúčtovat jednu platbu na otevřené
 * předpisy bucketu. Žádná DB, žádné side-effecty — vstup jsou už načtená
 * rezidua, výstup je {@see AllocationPlan}. Veškerá testovatelná logika
 * algoritmu (FIFO, VS signál, konzervativní brána, proporční domácí částka,
 * haléřové dorovnání) žije tady.
 *
 * Ledger pracuje s kladnými magnitudami (post modify_sign), takže planner
 * neřeší znaménka — jen velikosti. Sémantika: docs/accbal.md §5.3.
 */
final class AllocationPlanner
{
    /** Tolerance konzervativní brány = jeden haléř (rozhodnutí PRD). */
    private const TOLERANCE = 0.01;

    /**
     * @param float       $paymentAmount    částka platby v měně dokladu (kladná)
     * @param float       $paymentAmountHc  částka platby v domácí měně
     * @param string|null $paymentReference variabilní symbol platby (signál)
     * @param list<array{id: int, residual: float, due_date: ?string, payment_reference: ?string}> $openRequests
     *        otevřené předpisy bucketu s nenulovým reziduem (po odečtení ručních
     *        i dříve zapsaných allocations)
     * @param bool $enforceGate konzervativní brána (clearing → 311); false =
     *        best-effort přealokace na 311 (rematch), platba smí zůstat částečně
     *        nepokrytá
     */
    public function plan(
        float $paymentAmount,
        float $paymentAmountHc,
        ?string $paymentReference,
        array $openRequests,
        bool $enforceGate = true,
    ): AllocationPlan {
        if ($paymentAmount <= 0.0) {
            return AllocationPlan::skip(AllocationPlan::SKIP_ZERO_PAYMENT);
        }
        if ($openRequests === []) {
            return AllocationPlan::skip(AllocationPlan::SKIP_NO_OPEN_REQUESTS);
        }

        $totalResidual = 0.0;
        foreach ($openRequests as $r) {
            $totalResidual += (float) $r['residual'];
        }

        // Konzervativní brána: platba musí být celá alokovatelná (smí přesáhnout
        // součet reziduí nejvýš o haléř). Přeplatek → zůstává na clearingu.
        if ($enforceGate && $paymentAmount > round($totalResidual + self::TOLERANCE, 2)) {
            return AllocationPlan::skip(AllocationPlan::SKIP_OVERPAYMENT);
        }

        $ordered = $this->orderRequests($paymentReference, $openRequests);

        // FIFO spotřeba platby na seřazené předpisy.
        $items     = [];
        $remaining = round($paymentAmount, 2);
        foreach ($ordered as $r) {
            if ($remaining <= 0.0) {
                break;
            }
            $alloc = min($remaining, round((float) $r['residual'], 2));
            if ($alloc <= 0.0) {
                continue;
            }
            $items[]   = ['request_entry' => (int) $r['id'], 'amount' => round($alloc, 2), 'amount_hc' => 0.0];
            $remaining = round($remaining - $alloc, 2);
        }

        if ($items === []) {
            return AllocationPlan::skip(AllocationPlan::SKIP_NO_OPEN_REQUESTS);
        }

        // Zbytek do haléře (tolerance brány) dorovná poslední položka, aby
        // Σ amount == paymentAmount přesně. Větší zbytek (best-effort overpay)
        // se nechá nealokovaný — nesmí přetéct přes reziduum předpisu.
        if ($remaining > 0.0 && $remaining <= self::TOLERANCE) {
            $last = count($items) - 1;
            $items[$last]['amount'] = round($items[$last]['amount'] + $remaining, 2);
        }

        $this->fillHomeAmounts($items, round($paymentAmount, 2), round($paymentAmountHc, 2));

        return AllocationPlan::matched($items);
    }

    /**
     * VS jako signál: když variabilní symbol platby jednoznačně sedí na *právě
     * jeden* otevřený předpis, ten jde první (do výše rezidua), zbytek pokračuje
     * FIFO. Shoda na 0 nebo ≥2 → čisté FIFO (docs/accbal.md §5.3).
     *
     * FIFO: due_date ASC (NULL na konec), tie-break id ASC.
     *
     * @param list<array{id: int, residual: float, due_date: ?string, payment_reference: ?string}> $openRequests
     * @return list<array{id: int, residual: float, due_date: ?string, payment_reference: ?string}>
     */
    private function orderRequests(?string $paymentReference, array $openRequests): array
    {
        $fifo = $openRequests;
        usort($fifo, static function (array $a, array $b): int {
            $da = (string) ($a['due_date'] ?? '');
            $db = (string) ($b['due_date'] ?? '');
            // Prázdná splatnost (NULL) jde na konec.
            if ($da === '' && $db !== '') {
                return 1;
            }
            if ($da !== '' && $db === '') {
                return -1;
            }
            if ($da !== $db) {
                return $da <=> $db;
            }
            return (int) $a['id'] <=> (int) $b['id'];
        });

        $ref = $paymentReference !== null ? trim($paymentReference) : '';
        if ($ref === '') {
            return $fifo;
        }

        $hits = array_values(array_filter(
            $fifo,
            static fn(array $r): bool => trim((string) ($r['payment_reference'] ?? '')) === $ref,
        ));
        if (count($hits) !== 1) {
            return $fifo;
        }

        // Jednoznačná VS shoda → ten předpis první, zbytek v FIFO pořadí.
        $hitId = (int) $hits[0]['id'];
        $rest  = array_values(array_filter($fifo, static fn(array $r): bool => (int) $r['id'] !== $hitId));
        return array_merge($hits, $rest);
    }

    /**
     * Doplní domácí částky proporčně z platby
     * (`amount_hc = amount × paymentHc / payment`); haléřové dorovnání poslední
     * položky na cílový domácí součet. Při plné alokaci (Σ amount == payment)
     * je cílový součet == paymentAmountHc; při best-effort částečné alokaci je
     * proporční ke skutečně alokované částce (zbytek je kurzově/zbytkově otevřený).
     *
     * @param list<array{request_entry: int, amount: float, amount_hc: float}> $items
     */
    private function fillHomeAmounts(array &$items, float $paymentAmount, float $paymentAmountHc): void
    {
        if ($items === [] || $paymentAmount <= 0.0) {
            return;
        }
        $allocated = 0.0;
        foreach ($items as $item) {
            $allocated = round($allocated + $item['amount'], 2);
        }
        // Cílový domácí součet sady — při plné alokaci přesně paymentAmountHc.
        $targetHc = abs($allocated - $paymentAmount) <= self::TOLERANCE
            ? $paymentAmountHc
            : round($allocated * $paymentAmountHc / $paymentAmount, 2);

        $sumHc = 0.0;
        $count = count($items);
        foreach ($items as $i => $item) {
            if ($i === $count - 1) {
                $items[$i]['amount_hc'] = round($targetHc - $sumHc, 2);
                break;
            }
            $hc = round($item['amount'] * $paymentAmountHc / $paymentAmount, 2);
            $items[$i]['amount_hc'] = $hc;
            $sumHc = round($sumHc + $hc, 2);
        }
    }
}
