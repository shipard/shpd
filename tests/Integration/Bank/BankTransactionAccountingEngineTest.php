<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Bank;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Banka Fáze 3 W6 — bankovní mikroengine nad reálným DS (účtový rozvrh,
 * fiskální období). Transakce se vkládají přímo SQL; engine čte DB.
 * Vše vytvořené (transakce, deník, bankovní účet, doplněné účty) se
 * v tearDown maže.
 *
 * Pokrývá W6.1 (příjem nespárovaný → 221/261200), W6.2 (poplatek → 568/221)
 * a W6.7 (vyrovnanost Σ MD == Σ DAL). Lifecycle (W6.3/6.4), chyby (W6.5)
 * a clearing (W6.6) přijdou s handlerem a alertem.
 */
class BankTransactionAccountingEngineTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    private ?BankTransactionAccountingEngine $engine = null;

    /** @var list<int> */
    private array $createdTxs = [];
    /** @var list<int> */
    private array $createdBankAccounts = [];
    /** @var list<int> účty doplněné do rozvrhu jen pro test */
    private array $createdAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new BankTransactionAccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
        );
        $this->ensureFiscalPeriod();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $dibi = $this->db->getDibiConnection();
            foreach ($this->createdTxs as $id) {
                $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $id)->execute();
                $dibi->delete('economy_bank_transactions')->where('id = %i', $id)->execute();
            }
            foreach ($this->createdBankAccounts as $id) {
                $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $id)->execute();
            }
            foreach ($this->createdAccounts as $id) {
                $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
            }
        }
        parent::tearDown();
    }

    // ── W6.1 Příjem nespárovaný ──────────────────────────────────────────────

    public function testIncomingUnmatchedPostsBankAndClearing(): void
    {
        $bankAccountId = $this->ensureAccountByMask('221');
        $clearingId    = $this->ensureAccountByNumber('261200');
        $baId = $this->insertBankAccount($bankAccountId);

        $txId = $this->insertTx([
            'bank_account' => $baId,
            'direction'    => 1,
            'operation'    => 'payment.in',
            'amount'       => 1210.00,
            'amount_dom'   => 1210.00,
        ]);

        $result = $this->engine->accountTransaction($txId);
        $this->assertSame(1, $result['state'], 'Nespárovaný příjem se má zaúčtovat bez chyby');

        $rows = $this->journalOf($txId);
        $this->assertCount(2, $rows);

        foreach ($rows as $r) {
            $this->assertSame('bankTransaction', (string) $r['source_kind']);
            $this->assertSame($txId, (int) $r['bank_transaction']);
            $this->assertNull($r['doc_head']);
            $this->assertSame(0, (int) $r['is_error']);
        }

        $bank = $this->lineByPrefix($rows, '221');
        $this->assertEqualsWithDelta(1210.00, (float) $bank['money_dr'], 0.001, 'Banka MD');
        $this->assertEqualsWithDelta(0.0, (float) $bank['money_cr'], 0.001);

        $clearing = $this->lineByPrefix($rows, '261200');
        $this->assertEqualsWithDelta(1210.00, (float) $clearing['money_cr'], 0.001, 'Clearing DAL');
        $this->assertEqualsWithDelta(0.0, (float) $clearing['money_dr'], 0.001);
        $this->assertSame($clearingId, (int) $clearing['account'], 'Clearing řádek míří na účet 261200');

        $this->assertSame(1, $this->accountingStateOf($txId));
    }

    // ── W6.2 Výdaj poplatek ──────────────────────────────────────────────────

    public function testOutgoingFeePostsExpenseAndBank(): void
    {
        $bankAccountId = $this->ensureAccountByMask('221');
        $this->ensureAccountByMask('568'); // 568xxx existuje v rozvrhu
        $baId = $this->insertBankAccount($bankAccountId);

        $txId = $this->insertTx([
            'bank_account' => $baId,
            'direction'    => 2,
            'operation'    => 'fee.out',
            'amount'       => 50.00,
            'amount_dom'   => 50.00,
        ]);

        $result = $this->engine->accountTransaction($txId);
        $this->assertSame(1, $result['state']);

        $rows = $this->journalOf($txId);
        $this->assertCount(2, $rows);

        $expense = $this->lineByPrefix($rows, '568');
        $this->assertEqualsWithDelta(50.00, (float) $expense['money_dr'], 0.001, 'Poplatek MD (568)');

        $bank = $this->lineByPrefix($rows, '221');
        $this->assertEqualsWithDelta(50.00, (float) $bank['money_cr'], 0.001, 'Banka DAL (221)');

        $this->assertSame(1, $this->accountingStateOf($txId));
    }

    // ── accbal Fáze 0: razítkování platební identity ─────────────────────────

    public function testStampsPaymentReferenceDueDateNull(): void
    {
        $bankAccountId = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200');
        $baId = $this->insertBankAccount($bankAccountId);

        $txId = $this->insertTx([
            'bank_account'      => $baId,
            'direction'         => 1,
            'operation'         => 'payment.in',
            'amount'            => 1210.00,
            'amount_dom'        => 1210.00,
            'payment_reference' => '20260042',
            'specific_symbol'   => '777',
            'constant_symbol'   => '0308',
        ]);

        $this->assertSame(1, $this->engine->accountTransaction($txId)['state']);

        $rows = $this->journalOf($txId);
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('20260042', $r['payment_reference']);
            $this->assertSame('777', $r['specific_symbol']);
            $this->assertSame('0308', $r['constant_symbol']);
            $this->assertNull($r['due_date'], 'Bankovní transakce splatnost nemá');
        }
    }

    // ── accbal Fáze 2a: emise journalWritten ─────────────────────────────────

    public function testEmitsJournalWrittenOnAccountAndClear(): void
    {
        BankJournalSpy::$calls = [];
        $dispatcher = new JournalEventDispatcher([
            ['class' => BankJournalSpy::class, 'events' => ['journalWritten']],
        ]);
        $engine = new BankTransactionAccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
            $dispatcher,
        );

        $bankAccountId = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200');
        $baId = $this->insertBankAccount($bankAccountId);
        $txId = $this->insertTx([
            'bank_account' => $baId, 'direction' => 1, 'operation' => 'payment.in',
            'amount' => 1210.00, 'amount_dom' => 1210.00,
        ]);

        $engine->accountTransaction($txId);   // zápis
        $engine->accountTransaction($txId);   // reaccount
        $engine->clearTransaction($txId);     // vymazání

        $this->assertSame(
            [['bankTransaction', $txId], ['bankTransaction', $txId], ['bankTransaction', $txId]],
            BankJournalSpy::$calls,
        );
    }

    public function testJournalHandlerExceptionDoesNotBreakAccounting(): void
    {
        // Pozn.: spolknutá výjimka se zaloguje (ErrorLogger → stderr) — očekávané.
        $dispatcher = new JournalEventDispatcher([
            ['class' => ThrowingBankJournalSpy::class, 'events' => ['journalWritten']],
        ]);
        $engine = new BankTransactionAccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
            $dispatcher,
        );

        $bankAccountId = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200');
        $baId = $this->insertBankAccount($bankAccountId);
        $txId = $this->insertTx([
            'bank_account' => $baId, 'direction' => 1, 'operation' => 'payment.in',
            'amount' => 100.00, 'amount_dom' => 100.00,
        ]);

        $result = $engine->accountTransaction($txId);
        $this->assertSame(1, $result['state']);
        $this->assertSame(1, $this->accountingStateOf($txId));
        $this->assertCount(2, $this->journalOf($txId));
    }

    // ── W6.5 Chybový stav + alert ────────────────────────────────────────────

    public function testMissingBankAccountRaisesErrorAndAlert(): void
    {
        // Bankovní účet bez účtu pro pohyby (221xxx) → chybový řádek banky.
        $baId = $this->insertBankAccount(null);

        $txId = $this->insertTx([
            'bank_account' => $baId,
            'direction'    => 1,
            'operation'    => 'payment.in',
            'amount'       => 100.00,
            'amount_dom'   => 100.00,
        ]);

        $result = $this->engine->accountTransaction($txId);
        $this->assertSame(2, $result['state'], 'Nedohledaný bankovní účet → accounting_state 2');
        $this->assertSame(2, $this->accountingStateOf($txId));

        $rows = $this->journalOf($txId);
        $bank = $this->lineByPrefix($rows, '221');
        $this->assertSame(1, (int) $bank['is_error'], 'Bankovní řádek je chybový');

        // Alert check transakci najde (stav 40 + accounting_state 2)
        $check = new \Shipard\Module\Economy\Bank\Checks\BankAccountingErrorsCheck(
            $this->db,
            ConfigRuntime::load($this->realDsPath, 'cs'),
            'cs',
        );
        $ours = array_values(array_filter(
            $check->run(),
            fn($f) => $f->findingKey === (string) $txId,
        ));
        $this->assertCount(1, $ours);
        $this->assertStringContainsString('chybu účtování', $ours[0]->title);
        $this->assertSame($txId, $ours[0]->subjectRowId);
        $this->assertSame(414, $ours[0]->subjectTableId);

        // Po opravě (accounting_state 1) alert zmizí — reconciler auto-resolve
        $this->db->getDibiConnection()->update('economy_bank_transactions', ['accounting_state' => 1])
            ->where('id = %i', $txId)->execute();
        $after = array_filter(
            $check->run(),
            fn($f) => $f->findingKey === (string) $txId,
        );
        $this->assertCount(0, $after);
    }

    // ── W6.7 Vyrovnanost ─────────────────────────────────────────────────────

    public function testJournalIsBalanced(): void
    {
        $bankAccountId = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200');
        $this->ensureAccountByNumber('261300');
        $baId = $this->insertBankAccount($bankAccountId);

        $cases = [
            ['direction' => 1, 'operation' => 'payment.in',  'amount' => 999.99],
            ['direction' => 2, 'operation' => 'payment.out', 'amount' => 1234.56],
            ['direction' => 2, 'operation' => 'fee.out',     'amount' => 12.00],
        ];
        foreach ($cases as $c) {
            $txId = $this->insertTx([
                'bank_account' => $baId,
                'direction'    => $c['direction'],
                'operation'    => $c['operation'],
                'amount'       => $c['amount'],
                'amount_dom'   => $c['amount'],
            ]);
            $this->engine->accountTransaction($txId);

            $rows = $this->journalOf($txId);
            $sumDr = 0.0;
            $sumCr = 0.0;
            foreach ($rows as $r) {
                $sumDr += (float) $r['money_dr'];
                $sumCr += (float) $r['money_cr'];
            }
            $this->assertEqualsWithDelta($sumDr, $sumCr, 0.001, "Σ MD == Σ DAL pro {$c['operation']}");
        }
    }

    // ── W6.6 Clearing nese nespárované ───────────────────────────────────────

    public function testClearingCarriesUnmatchedMovements(): void
    {
        $bankAccountId = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200');
        $this->ensureAccountByNumber('261300');
        $baId = $this->insertBankAccount($bankAccountId);

        // Příjem nespárovaný → 261200 DAL 1210
        $inId = $this->insertTx([
            'bank_account' => $baId, 'direction' => 1, 'operation' => 'payment.in',
            'amount' => 1210.00, 'amount_dom' => 1210.00,
        ]);
        // Výdaj nespárovaný → 261300 MD 500
        $outId = $this->insertTx([
            'bank_account' => $baId, 'direction' => 2, 'operation' => 'payment.out',
            'amount' => 500.00, 'amount_dom' => 500.00,
        ]);
        $this->engine->accountTransaction($inId);
        $this->engine->accountTransaction($outId);

        // 261200 nese přesně příjem (DAL), 261300 přesně výdaj (MD)
        $in = $this->clearingTurnover('261200');
        $this->assertEqualsWithDelta(0.0, $in['dr'], 0.001);
        $this->assertEqualsWithDelta(1210.00, $in['cr'], 0.001, 'Clearing 261200 nese nespárovaný příjem');

        $out = $this->clearingTurnover('261300');
        $this->assertEqualsWithDelta(500.00, $out['dr'], 0.001, 'Clearing 261300 nese nespárovaný výdaj');
        $this->assertEqualsWithDelta(0.0, $out['cr'], 0.001);
    }

    /** @return array{dr: float, cr: float} obrat clearing účtu napříč deníkem našich transakcí */
    private function clearingTurnover(string $number): array
    {
        $row = $this->db->fetchRow(
            'SELECT COALESCE(SUM(money_dr),0) AS dr, COALESCE(SUM(money_cr),0) AS cr
             FROM economy_accounting_journal
             WHERE account_number = %s AND bank_transaction IN %in',
            $number,
            $this->createdTxs,
        );
        return ['dr' => (float) $row['dr'], 'cr' => (float) $row['cr']];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function ensureFiscalPeriod(): void
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months
             WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($row === null) {
            $this->markTestSkipped('DS nemá fiskální období pro ' . self::ACC_DATE);
        }
    }

    /** Účet dle masky (LIKE prefix) — vrátí id existujícího analytického účtu. */
    private function ensureAccountByMask(string $mask): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts
             WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80)
             ORDER BY number LIMIT 1',
            $mask,
        );
        if ($row === null) {
            $this->markTestSkipped("DS nemá analytický účet pro masku {$mask}");
        }
        return (int) $row['id'];
    }

    /**
     * Účet daného čísla — vrátí id existujícího, nebo ho doplní (clearing
     * účty 261200/261300 nejsou na DS se skipProvisioning) a označí k úklidu.
     */
    private function ensureAccountByNumber(string $number): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s AND docState IN (10,40,80) LIMIT 1',
            $number,
        );
        if ($row !== null) {
            return (int) $row['id'];
        }

        $dibi = $this->db->getDibiConnection();
        $structure = AccountDocument::deriveStructure($number);
        $dibi->insert('economy_accounting_accounts', array_merge($structure, [
            'number'       => $number,
            'name'         => 'IT clearing ' . $number,
            'short_name'   => 'IT ' . $number,
            'account_kind' => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdAccounts[] = $id;
        return $id;
    }

    private function insertBankAccount(?int $accountingAccountId): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'IT' . substr(uniqid(), -8),
            'name'               => 'IT test bankovní účet',
            'currency'           => 'czk',
            'accounting_account' => $accountingAccountId,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdBankAccounts[] = $id;
        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function insertTx(array $overrides): int
    {
        $dibi = $this->db->getDibiConnection();
        $tx = array_merge([
            'currency'         => 'czk',
            'exchange_rate'    => 1,
            'date_transaction' => self::ACC_DATE,
            'counterparty_name' => 'IT protistrana',
            'accounting_state' => 0,
            'docState'         => 40,
            'docStateMain'     => 3,
        ], $overrides);
        $dibi->insert('economy_bank_transactions', $tx)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdTxs[] = $id;
        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function journalOf(int $txId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE bank_transaction = %i ORDER BY id',
            $txId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function lineByPrefix(array $rows, string $prefix): array
    {
        foreach ($rows as $r) {
            if (str_starts_with((string) $r['account_number'], $prefix)) {
                return $r;
            }
        }
        $this->fail("Řádek deníku s účtem {$prefix}* nenalezen");
    }

    private function accountingStateOf(int $txId): int
    {
        $row = $this->db->fetchRow(
            'SELECT accounting_state FROM economy_bank_transactions WHERE id = %i',
            $txId,
        );
        return (int) $row['accounting_state'];
    }
}

/** Spy journal handler — zaznamenává (sourceKind, sourceId) staticky. */
class BankJournalSpy extends AbstractJournalEventHandler
{
    /** @var list<array{0: string, 1: int}> */
    public static array $calls = [];

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$calls[] = [$sourceKind, $sourceId];
    }
}

/** Vyhazuje výjimku — ověřuje, že nespadne účtování. */
class ThrowingBankJournalSpy extends AbstractJournalEventHandler
{
    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        throw new \RuntimeException('saldo boom');
    }
}
