<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;

/**
 * Matcher saldokonta (Fáze 3): přesune nespárované bankovní úhrady z clearingu
 * na 311/321 a naváže je na otevřené předpisy (allocations). Pracuje výhradně
 * nad saldo deníkem (economy_accbal_ledger) + plánuje přes {@see AllocationPlanner}.
 *
 * Spárovanost = hodnota `operation` transakce (docs/accbal.md §5.1): matcher
 * nastaví `payment.in.matched` / `payment.out.matched` a zavolá stávající
 * BankTransactionAccountingEngine — clearing → 311/321 je výstup řetězce
 * `operation → cat → maska`. Po reaccountu engine synchronně vyšle
 * `journalWritten` → LedgerGenerator re-derivuje pohyby (clearing zmizí, vznikne
 * 311/321 úhrada) a matcher na ni zapíše allocations.
 *
 * Engine ani LedgerGenerator se nemění; matcher je tenká vrstva nad nimi, vzor
 * BankController::reaccount. Běh je monotónní a idempotentní (spárované platby
 * už nejsou na clearingu → nejsou kandidáti).
 *
 * Algoritmus a rozhodnutí: docs/accbal.md §5 + §10 #13–#17.
 */
final class BalanceMatcher
{
    private const DIRECTION_IN = 1;

    /** Aktivní docState (archivní sada) pro skupiny saldokont. */
    private const ACTIVE_STATES = [10, 40, 80];

    private AllocationPlanner $planner;

    /** @var array<string, int> code → balance id (cache) */
    private array $balanceIds = [];

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly JournalEventDispatcher $journalEvents,
        private readonly ?DataSourceConfig $dsConfig = null,
    ) {
        $this->planner = new AllocationPlanner();
    }

    // ── Veřejné vstupy ───────────────────────────────────────────────────────

    /**
     * Spáruje jednu bankovní úhradu (clearing → 311/321 + allocations).
     * dryRun: vrátí plán bez jakékoli změny.
     */
    public function matchTransaction(int $txId, bool $dryRun = false): MatchResult
    {
        $candidate = $this->loadClearingCandidate($txId);
        if ($candidate === null) {
            return MatchResult::skipped($txId, 'not_on_clearing');
        }

        $tx = $this->db->fetch(
            'SELECT [direction], [partner], [operation] FROM [economy_bank_transactions] WHERE [id] = %i',
            $txId,
        );
        if ($tx === null) {
            return MatchResult::skipped($txId, 'transaction_missing');
        }
        $direction = (int) ($tx['direction'] ?? 0);

        // Routing: směr → cíl. Opačné páry MVP neřeší.
        $targetCode = match ($direction) {
            self::DIRECTION_IN => 'receivables',
            2                  => 'payables',
            default            => null,
        };
        if ($targetCode === null) {
            return MatchResult::skipped($txId, 'unsupported_direction', (float) $candidate['amount']);
        }
        $targetBalance = $this->balanceId($targetCode);
        if ($targetBalance === null) {
            return MatchResult::skipped($txId, 'target_balance_missing', (float) $candidate['amount']);
        }

        $partner = $candidate['partner'] !== null ? (int) $candidate['partner'] : null;
        if ($partner === null && isset($tx['partner']) && $tx['partner'] !== null) {
            $partner = (int) $tx['partner'];
        }
        if ($partner === null) {
            return MatchResult::skipped($txId, 'no_partner', (float) $candidate['amount']);
        }

        $currency = $candidate['currency'] !== null ? (string) $candidate['currency'] : null;
        $openRequests = $this->loadOpenRequests($targetBalance, $partner, $currency);

        $plan = $this->planner->plan(
            (float) $candidate['amount'],
            (float) $candidate['amount_hc'],
            $candidate['payment_reference'] !== null ? (string) $candidate['payment_reference'] : null,
            $openRequests,
            true,
        );

        if (!$plan->matched) {
            return MatchResult::skipped($txId, (string) $plan->skipReason, (float) $candidate['amount']);
        }

        if ($dryRun) {
            return MatchResult::planned($txId, $targetCode, $partner, $currency, (float) $candidate['amount'], $plan->items);
        }

        // Exekuce: nastav matched operaci (+partner), přeúčtuj → re-derivace.
        $matchedOp = $direction === self::DIRECTION_IN ? 'payment.in.matched' : 'payment.out.matched';
        $this->db->update('economy_bank_transactions', [
            'operation' => $matchedOp,
            'partner'   => $partner,
        ])->where('[id] = %i', $txId)->execute();

        $engine = new BankTransactionAccountingEngine($this->db, $this->config, $this->journalEvents);
        $engine->accountTransaction($txId);

        // Re-derivace už proběhla (journalWritten je synchronní) → najdi novou úhradu.
        $paymentMoveId = $this->findPaymentMove($txId, $targetBalance);
        if ($paymentMoveId === null) {
            ErrorLogger::warn(sprintf(
                'BalanceMatcher: úhradový pohyb tx #%d na saldokontu %d po reaccountu nenalezen — alokace přeskočena',
                $txId,
                $targetBalance,
            ));
            return MatchResult::routedUnallocated($txId, $targetCode, $partner, $currency, (float) $candidate['amount']);
        }

        $this->writeAllocations($paymentMoveId, $plan->items);

        return MatchResult::allocated($txId, $targetCode, $partner, $currency, (float) $candidate['amount'], $plan->items);
    }

    /**
     * Dávka: iteruje clearingové kandidáty (date_transaction ASC, id ASC).
     *
     * @param array{partner?: int, fiscalYear?: int} $filters
     */
    public function matchAll(array $filters = [], bool $dryRun = false): MatchSummary
    {
        $summary = new MatchSummary();
        foreach ($this->loadCandidateTxIds($filters) as $txId) {
            $summary->add($this->matchTransaction($txId, $dryRun));
        }
        return $summary;
    }

    /**
     * Přegenerace bucketu: smaž auto allocations + best-effort přealokuj platby,
     * které už jsou na cílovém saldokontu (routing se nehýbe, žádná brána).
     * Ruční allocations zůstávají a počítají se do spotřebovaného rezidua.
     */
    public function rematchBucket(int $partner, int $balance, string $currency): MatchSummary
    {
        $summary = new MatchSummary();

        $this->db->begin();
        try {
            // Smaž jen auto allocations plateb tohoto bucketu.
            $this->db->query(
                'DELETE FROM [economy_accbal_allocations]
                 WHERE [created_by] = 0 AND [payment_entry] IN (
                     SELECT [id] FROM [economy_accbal_ledger]
                     WHERE [balance] = %i AND [partner] = %i AND [currency] = %s AND [bal_side] = 1
                 )',
                $balance, $partner, $currency,
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $payments = $this->db->fetchAll(
            'SELECT [id], [amount], [amount_hc], [payment_reference]
             FROM [economy_accbal_ledger]
             WHERE [balance] = %i AND [partner] = %i AND [currency] = %s AND [bal_side] = 1
             ORDER BY [id]',
            $balance, $partner, $currency,
        );

        foreach ($payments as $p) {
            $paymentMoveId = (int) $p['id'];
            $openRequests  = $this->loadOpenRequests($balance, $partner, $currency);
            $plan = $this->planner->plan(
                (float) $p['amount'],
                (float) $p['amount_hc'],
                $p['payment_reference'] !== null ? (string) $p['payment_reference'] : null,
                $openRequests,
                false, // best-effort, žádná brána
            );
            if (!$plan->matched) {
                $summary->add(MatchResult::skipped($paymentMoveId, (string) $plan->skipReason, (float) $p['amount']));
                continue;
            }
            $this->writeAllocations($paymentMoveId, $plan->items);
            $summary->add(MatchResult::allocated($paymentMoveId, '', $partner, $currency, (float) $p['amount'], $plan->items));
        }

        return $summary;
    }

    /**
     * Úplné rozpárování: `operation` zpět na nespárovanou → reaccount. Re-derivace
     * smaže 311/321 pohyb a cascade všechny jeho allocations (auto i ruční) —
     * vědomá destruktivní akce.
     */
    public function unmatch(int $txId): void
    {
        $tx = $this->db->fetch(
            'SELECT [direction] FROM [economy_bank_transactions] WHERE [id] = %i',
            $txId,
        );
        if ($tx === null) {
            throw new \DomainException("Bankovní transakce #{$txId} nenalezena");
        }
        $unmatchedOp = (int) ($tx['direction'] ?? 0) === self::DIRECTION_IN ? 'payment.in' : 'payment.out';

        $this->db->update('economy_bank_transactions', ['operation' => $unmatchedOp])
            ->where('[id] = %i', $txId)
            ->execute();

        $engine = new BankTransactionAccountingEngine($this->db, $this->config, $this->journalEvents);
        $engine->accountTransaction($txId);
    }

    // ── Načítání ──────────────────────────────────────────────────────────────

    /**
     * Clearingový kandidát = úhradový pohyb (bal_side=1) transakce ve skupině
     * „Nespárované platby". Tím se vyloučí poplatky/úroky i už spárované platby.
     *
     * @return array<string, mixed>|null
     */
    private function loadClearingCandidate(int $txId): ?array
    {
        $clearing = $this->balanceId('unmatched_payments');
        if ($clearing === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [partner], [currency], [amount], [amount_hc], [payment_reference]
             FROM [economy_accbal_ledger]
             WHERE [source_kind] = %s AND [source_id] = %i AND [balance] = %i AND [bal_side] = 1',
            'bankTransaction', $txId, $clearing,
        );
        return $row?->toArray();
    }

    /**
     * Otevřené předpisy bucketu (bal_side=0, balance, partner, currency) napříč
     * fiskálními roky; reziduum = amount − Σ allocations.amount > 0.
     *
     * @return list<array{id: int, residual: float, due_date: ?string, payment_reference: ?string}>
     */
    private function loadOpenRequests(int $balance, int $partner, ?string $currency): array
    {
        $rows = $this->db->fetchAll(
            'SELECT l.[id], l.[amount], l.[due_date], l.[payment_reference],
                    COALESCE(SUM(a.[amount]), 0) AS allocated
             FROM [economy_accbal_ledger] l
             LEFT JOIN [economy_accbal_allocations] a ON a.[request_entry] = l.[id]
             WHERE l.[bal_side] = 0 AND l.[balance] = %i AND l.[partner] = %i
               AND l.[currency] ' . ($currency === null ? 'IS NULL' : '= %s') . '
             GROUP BY l.[id], l.[amount], l.[due_date], l.[payment_reference]
             HAVING (l.[amount] - COALESCE(SUM(a.[amount]), 0)) > 0
             ORDER BY l.[id]',
            ...($currency === null ? [$balance, $partner] : [$balance, $partner, $currency]),
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'                => (int) $r['id'],
                'residual'          => round((float) $r['amount'] - (float) $r['allocated'], 2),
                'due_date'          => $this->dateString($r['due_date'] ?? null),
                'payment_reference' => $r['payment_reference'] !== null ? (string) $r['payment_reference'] : null,
            ];
        }
        return $out;
    }

    /** Úhradový pohyb transakce na cílovém saldokontu po reaccountu. */
    private function findPaymentMove(int $txId, int $targetBalance): ?int
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_accbal_ledger]
             WHERE [source_kind] = %s AND [source_id] = %i AND [balance] = %i AND [bal_side] = 1',
            'bankTransaction', $txId, $targetBalance,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Clearingoví kandidáti seřazení dle data transakce (FIFO plateb v dávce).
     *
     * @param array{partner?: int, fiscalYear?: int} $filters
     * @return list<int> txIds
     */
    private function loadCandidateTxIds(array $filters): array
    {
        $clearing = $this->balanceId('unmatched_payments');
        if ($clearing === null) {
            return [];
        }

        $conds = ['l.[balance] = %i', 'l.[bal_side] = %i', 'l.[bank_transaction] IS NOT NULL'];
        $args  = [$clearing, 1];
        if (isset($filters['partner'])) {
            $conds[] = 'l.[partner] = %i';
            $args[]  = (int) $filters['partner'];
        }
        if (isset($filters['fiscalYear'])) {
            $conds[] = 'l.[fiscal_year] = %i';
            $args[]  = (int) $filters['fiscalYear'];
        }

        $rows = $this->db->fetchAll(
            'SELECT l.[bank_transaction] AS tx
             FROM [economy_accbal_ledger] l
             JOIN [economy_bank_transactions] t ON t.[id] = l.[bank_transaction]
             WHERE ' . implode(' AND ', $conds) . '
             ORDER BY t.[date_transaction] ASC, t.[id] ASC',
            ...$args,
        );

        return array_map(static fn($r) => (int) $r['tx'], $rows);
    }

    // ── Zápis ───────────────────────────────────────────────────────────────

    /**
     * @param list<array{request_entry: int, amount: float, amount_hc: float}> $items
     */
    private function writeAllocations(int $paymentMoveId, array $items): void
    {
        if ($items === []) {
            return;
        }
        $this->db->begin();
        try {
            foreach ($items as $item) {
                $this->db->insert('economy_accbal_allocations', [
                    'payment_entry' => $paymentMoveId,
                    'request_entry' => $item['request_entry'],
                    'amount'        => $item['amount'],
                    'amount_hc'     => $item['amount_hc'],
                    'created_by'    => 0,
                ])->execute();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private function balanceId(string $code): ?int
    {
        if (array_key_exists($code, $this->balanceIds)) {
            return $this->balanceIds[$code];
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_accbal_balances] WHERE [code] = %s AND [docState] IN %in',
            $code,
            self::ACTIVE_STATES,
        );
        return $this->balanceIds[$code] = ($row !== null ? (int) $row['id'] : null);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
