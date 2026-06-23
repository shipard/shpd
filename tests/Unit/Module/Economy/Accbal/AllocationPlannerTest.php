<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Accbal\AllocationPlan;
use Shipard\Module\Economy\Accbal\AllocationPlanner;

/**
 * Čistý unit test alokačního jádra (bez DB). Pokrývá FIFO, VS signál,
 * konzervativní bránu, haléřové dorovnání a best-effort režim.
 */
class AllocationPlannerTest extends TestCase
{
    private AllocationPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AllocationPlanner();
    }

    /** @return array{id: int, residual: float, due_date: ?string, payment_reference: ?string} */
    private function req(int $id, float $residual, ?string $due = null, ?string $vs = null): array
    {
        return ['id' => $id, 'residual' => $residual, 'due_date' => $due, 'payment_reference' => $vs];
    }

    public function testNoOpenRequestsSkips(): void
    {
        $plan = $this->planner->plan(100.0, 100.0, null, []);
        $this->assertFalse($plan->matched);
        $this->assertSame(AllocationPlan::SKIP_NO_OPEN_REQUESTS, $plan->skipReason);
    }

    public function testZeroPaymentSkips(): void
    {
        $plan = $this->planner->plan(0.0, 0.0, null, [$this->req(1, 100.0)]);
        $this->assertSame(AllocationPlan::SKIP_ZERO_PAYMENT, $plan->skipReason);
    }

    public function testOverpaymentGateSkips(): void
    {
        // 600 na rezidua 250+250 = 500 → přeplatek → skip.
        $plan = $this->planner->plan(600.0, 600.0, null, [$this->req(1, 250.0), $this->req(2, 250.0)]);
        $this->assertFalse($plan->matched);
        $this->assertSame(AllocationPlan::SKIP_OVERPAYMENT, $plan->skipReason);
    }

    public function testFifoOrderByDueDate(): void
    {
        // Předpisy v opačném pořadí splatnosti; očekáváme nejdřív nejstarší.
        $plan = $this->planner->plan(300.0, 300.0, null, [
            $this->req(1, 200.0, '2026-05-01'),
            $this->req(2, 200.0, '2026-03-01'),
        ]);
        $this->assertTrue($plan->matched);
        $this->assertSame(2, $plan->items[0]['request_entry'], 'Nejstarší splatnost první');
        $this->assertEqualsWithDelta(200.0, $plan->items[0]['amount'], 0.001);
        $this->assertSame(1, $plan->items[1]['request_entry']);
        $this->assertEqualsWithDelta(100.0, $plan->items[1]['amount'], 0.001);
    }

    public function testNullDueDateGoesLast(): void
    {
        $plan = $this->planner->plan(150.0, 150.0, null, [
            $this->req(1, 100.0, null),
            $this->req(2, 100.0, '2026-03-01'),
        ]);
        $this->assertSame(2, $plan->items[0]['request_entry'], 'Předpis s due_date první');
        $this->assertSame(1, $plan->items[1]['request_entry'], 'NULL due_date na konec');
    }

    public function testVsSignalSingleMatchTakesPrecedence(): void
    {
        // VS sedí jednoznačně na req 3 (nejnovější) → ten první navzdory FIFO.
        $plan = $this->planner->plan(100.0, 100.0, 'VS999', [
            $this->req(1, 100.0, '2026-01-01', 'OTHER'),
            $this->req(2, 100.0, '2026-02-01', null),
            $this->req(3, 100.0, '2026-09-01', 'VS999'),
        ]);
        $this->assertSame(3, $plan->items[0]['request_entry'], 'VS shoda přebíjí FIFO');
        $this->assertEqualsWithDelta(100.0, $plan->items[0]['amount'], 0.001);
    }

    public function testVsSignalAmbiguousFallsBackToFifo(): void
    {
        // VS sedí na dva předpisy → ignoruj VS, čisté FIFO (dle splatnosti).
        $plan = $this->planner->plan(100.0, 100.0, 'DUP', [
            $this->req(1, 100.0, '2026-05-01', 'DUP'),
            $this->req(2, 100.0, '2026-03-01', 'DUP'),
        ]);
        $this->assertSame(2, $plan->items[0]['request_entry'], 'Dvojznačné VS → FIFO');
    }

    public function testSixHundredOnThreeTwoFifty(): void
    {
        // Kontrolní příklad z PRD/§5.3: 600 → 250/250/100.
        $plan = $this->planner->plan(600.0, 600.0, null, [
            $this->req(1, 250.0, '2026-03-01'),
            $this->req(2, 250.0, '2026-04-01'),
            $this->req(3, 250.0, '2026-05-01'),
        ]);
        $this->assertTrue($plan->matched);
        $this->assertCount(3, $plan->items);
        $this->assertEqualsWithDelta(250.0, $plan->items[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(250.0, $plan->items[1]['amount'], 0.001);
        $this->assertEqualsWithDelta(100.0, $plan->items[2]['amount'], 0.001);
        $this->assertEqualsWithDelta(600.0, $this->sum($plan, 'amount'), 0.001);
    }

    public function testPennyReconciliationBothCurrencies(): void
    {
        // Cizoměnová platba 100 EUR = 2528.53 CZK, rozdělená na 3 předpisy.
        $plan = $this->planner->plan(100.0, 2528.53, null, [
            $this->req(1, 33.33, '2026-03-01'),
            $this->req(2, 33.33, '2026-04-01'),
            $this->req(3, 33.34, '2026-05-01'),
        ]);
        $this->assertTrue($plan->matched);
        $this->assertEqualsWithDelta(100.0, $this->sum($plan, 'amount'), 0.0001, 'Σ amount == platba (měna dokladu)');
        $this->assertEqualsWithDelta(2528.53, $this->sum($plan, 'amount_hc'), 0.0001, 'Σ amount_hc == platba (domácí)');
    }

    public function testGateToleranceAllowsOneHaler(): void
    {
        // Platba o haléř větší než součet reziduí → projde, dorovná poslední.
        $plan = $this->planner->plan(500.01, 500.01, null, [
            $this->req(1, 250.0, '2026-03-01'),
            $this->req(2, 250.0, '2026-04-01'),
        ]);
        $this->assertTrue($plan->matched, 'Haléřová tolerance brány');
        $this->assertEqualsWithDelta(500.01, $this->sum($plan, 'amount'), 0.0001);
    }

    public function testBestEffortPartialAllocationNoGate(): void
    {
        // Bez brány (rematch): platba 600 > rezidua 500 → alokuje 500, zbytek
        // zůstane nealokovaný (žádné přetečení přes reziduum předpisu).
        $plan = $this->planner->plan(600.0, 600.0, null, [
            $this->req(1, 250.0, '2026-03-01'),
            $this->req(2, 250.0, '2026-04-01'),
        ], false);
        $this->assertTrue($plan->matched);
        $this->assertEqualsWithDelta(500.0, $this->sum($plan, 'amount'), 0.001, 'Alokuje jen rezidua');
        $this->assertEqualsWithDelta(250.0, $plan->items[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(250.0, $plan->items[1]['amount'], 0.001);
    }

    public function testManualAllocationsReduceResidual(): void
    {
        // Ruční allocation se promítne jako menší reziduum předaného předpisu —
        // matcher na něj nesáhne, jen dorovná zbytek.
        $plan = $this->planner->plan(100.0, 100.0, null, [
            $this->req(1, 30.0, '2026-03-01'),  // reziduum už po ruční allocaci
            $this->req(2, 100.0, '2026-04-01'),
        ]);
        $this->assertEqualsWithDelta(30.0, $plan->items[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(70.0, $plan->items[1]['amount'], 0.001);
    }

    private function sum(AllocationPlan $plan, string $key): float
    {
        $s = 0.0;
        foreach ($plan->items as $item) {
            $s += $item[$key];
        }
        return $s;
    }
}
