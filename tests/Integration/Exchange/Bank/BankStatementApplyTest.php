<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Bank;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Bank\BankStatementApplier;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Banka Fáze 4 W5.2–W5.5 — apply výměnného formátu shpd.bank.statement.v1
 * přes BankStatementApplier nad reálným DS. targetState 40 vytvoří výpis +
 * transakce a zaúčtuje je (přes sdílené apply jádro + event handler);
 * dedup idempotence, targetState 10, reconciliace.
 */
class BankStatementApplyTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    private ?BankStatementApplier $applier = null;

    private int $bankAccountId = 0;
    /** @var list<int> */
    private array $createdAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months
             WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fm === null) {
            $this->markTestSkipped('DS nemá fiskální období pro ' . self::ACC_DATE);
        }

        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $this->db->getDibiConnection(), $config);

        $this->applier = BankStatementApplier::create(
            $this->db->getDibiConnection(),
            $config,
            $this->dsConfig,
            $registry,
            $this->tables,
            $dispatcher,
        );

        // Účet pro pohyby (221) + clearing + bankovní spojení s vazbou na 221.
        $bankAccount = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200');
        $this->ensureAccountByNumber('261300');

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'ITX' . substr(uniqid(), -7),
            'name'               => 'IT F4 účet',
            'currency'           => 'czk',
            'accounting_account' => $bankAccount,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $this->bankAccountId = (int) $dibi->getInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->bankAccountId > 0) {
            $dibi = $this->db->getDibiConnection();
            $stmtIds = $dibi->query(
                'SELECT [id] FROM [economy_bank_statements] WHERE [bank_account] = %i',
                $this->bankAccountId,
            )->fetchPairs(null, 'id');
            $txIds = $dibi->query(
                'SELECT [id] FROM [economy_bank_transactions] WHERE [bank_account] = %i',
                $this->bankAccountId,
            )->fetchPairs(null, 'id');
            foreach ($txIds as $tid) {
                $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', (int) $tid)->execute();
            }
            $dibi->delete('economy_bank_transactions')->where('bank_account = %i', $this->bankAccountId)->execute();
            $dibi->delete('economy_bank_statements')->where('bank_account = %i', $this->bankAccountId)->execute();
            $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $this->bankAccountId)->execute();
            foreach ($this->createdAccounts as $id) {
                $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
            }
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'format'        => 'shpd.bank.statement',
            'formatVersion' => '1.0',
            'source'        => ['kind' => 'import.oldShipard', 'raw' => ['oldNdx' => 7]],
            'bankAccountId' => $this->bankAccountId,
            'statement'     => [
                'statementNumber' => 'IT-F4/2026-06',
                'periodStart'     => '2026-06-01',
                'periodEnd'       => '2026-06-30',
                'openingBalance'  => 0.0,
                'closingBalance'  => 710.0, // 1210 − 500
                'currency'        => 'CZK',
            ],
            'transactions'  => [
                [
                    'externalId'       => 'oldtx-in-1',
                    'amount'           => 1210.00,
                    'dateTransaction'  => self::ACC_DATE,
                    'counterpartyName' => 'IT příjemce',
                ],
                [
                    'externalId'       => 'oldtx-out-1',
                    'amount'           => -500.00,
                    'dateTransaction'  => self::ACC_DATE,
                    'counterpartyName' => 'IT plátce',
                ],
            ],
            'applyOptions'  => ['targetState' => 40],
        ], $overrides);
    }

    // ── W5.2 Apply ve stavu 40 → zaúčtováno ──────────────────────────────────

    public function testApplyState40CreatesAndAccounts(): void
    {
        $result = $this->applier->apply($this->payload());
        $this->assertTrue($result->success, 'apply selhal: ' . ($result->errorMessage ?? ''));
        $this->assertGreaterThan(0, $result->savedId, 'savedStatementId');

        // Výpis (hlavička) zrcadlí targetState: „hotový" → V pořádku (40), ne koncept.
        $stmt = $this->db->getDibiConnection()->fetch(
            'SELECT docState, docStateMain FROM economy_bank_statements WHERE id = %i',
            $result->savedId,
        );
        $this->assertSame(40, (int) $stmt['docState'], 'výpis ve stavu V pořádku (40)');
        $this->assertSame(3, (int) $stmt['docStateMain'], 'docStateMain pro stav 40');

        $rows = $this->txRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame(40, (int) $r['docState'], 'transakce ve stavu 40');
            $this->assertSame(1, (int) $r['accounting_state'], 'zaúčtováno bez chyby');
        }

        // Deník: 2 řádky/transakce, source_kind bankTransaction, banka + clearing
        $journal = $this->db->getDibiConnection()->fetchAll(
            'SELECT j.* FROM economy_accounting_journal j
             JOIN economy_bank_transactions t ON t.id = j.bank_transaction
             WHERE t.bank_account = %i ORDER BY j.id',
            $this->bankAccountId,
        );
        $this->assertCount(4, $journal, '2 transakce × 2 řádky');
        foreach ($journal as $j) {
            $this->assertSame('bankTransaction', (string) $j['source_kind']);
            $this->assertNull($j['doc_head']);
        }
        $accounts = array_map(static fn($j) => substr((string) $j['account_number'], 0, 6), $journal);
        $this->assertContains('261200', $accounts, 'clearing příjmu');
        $this->assertContains('261300', $accounts, 'clearing výdaje');
    }

    // ── W5.3 Dedup idempotence ───────────────────────────────────────────────

    public function testSecondApplyIsIdempotent(): void
    {
        $this->applier->apply($this->payload());
        $second = $this->applier->apply($this->payload());

        $this->assertTrue($second->success);
        $this->assertSame(0, $second->canonical['_result']['created'], 'opakovaný apply nezakládá');
        $this->assertSame(2, $second->canonical['_result']['skipped']);
        $this->assertCount(2, $this->txRows(), 'žádné zdvojení');
    }

    // ── W5.4 Apply ve stavu 10 → bez deníku ──────────────────────────────────

    public function testApplyState10LeavesUnaccounted(): void
    {
        $result = $this->applier->apply($this->payload(['applyOptions' => ['targetState' => 10]]));
        $this->assertTrue($result->success);

        // Výpis zůstává koncept (10) — souborový import i konceptová migrace.
        $stmt = $this->db->getDibiConnection()->fetch(
            'SELECT docState FROM economy_bank_statements WHERE id = %i',
            $result->savedId,
        );
        $this->assertSame(10, (int) $stmt['docState'], 'výpis zůstává koncept (10)');

        $rows = $this->txRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame(10, (int) $r['docState'], 'transakce Nová');
            $this->assertSame(0, (int) $r['accounting_state']);
        }
        $journalCount = (int) $this->db->getDibiConnection()->fetchSingle(
            'SELECT COUNT(*) FROM economy_accounting_journal j
             JOIN economy_bank_transactions t ON t.id = j.bank_transaction
             WHERE t.bank_account = %i',
            $this->bankAccountId,
        );
        $this->assertSame(0, $journalCount, 'stav 10 negeneruje deník');
    }

    // ── W5.5 Reconciliace ────────────────────────────────────────────────────

    public function testReconciliationMatchesOnBalancedStatement(): void
    {
        $result = $this->applier->apply($this->payload());
        $this->assertTrue($result->success);

        $state = (int) $this->db->getDibiConnection()->fetchSingle(
            'SELECT reconciliation_state FROM economy_bank_statements WHERE id = %i',
            $result->savedId,
        );
        $this->assertSame(1, $state, 'sedící zůstatky → reconciliation_state 1');
        $this->assertSame(1, $result->canonical['_result']['reconciliation']);
    }

    // ── validate / preview bez zápisu ────────────────────────────────────────

    public function testValidateRejectsMissingBankAccount(): void
    {
        $payload = $this->payload();
        unset($payload['bankAccountId']);
        $result = $this->applier->validate($payload);

        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertCount(0, $this->txRows(), 'validate nezapisuje');
    }

    public function testPreviewReportsCountsWithoutWriting(): void
    {
        $result = $this->applier->preview($this->payload());

        $this->assertTrue($result->success);
        $this->assertTrue($result->canonical['_preview']['bankAccountExists']);
        $this->assertSame(2, $result->canonical['_preview']['toCreate']);
        $this->assertSame(0, $result->canonical['_preview']['toSkip']);
        $this->assertCount(0, $this->txRows(), 'preview nezapisuje');
    }

    public function testApplyCarriesExchangeRateIntoAmountDom(): void
    {
        // exchange_rate je nezávislý multiplikátor (kurz za jednotku); i na CZK
        // účtu ověří celý řetězec schéma → DTO → service → DB (amount_dom = amount × rate).
        $this->applier->apply($this->payload([
            'statement'    => [
                'statementNumber' => 'FX-1',
                'periodStart'     => '2026-06-01',
                'periodEnd'       => '2026-06-30',
                'openingBalance'  => 0.0,
                'closingBalance'  => 100.0,
                'currency'        => 'CZK',
            ],
            'transactions' => [[
                'externalId'      => 'fx-in-1',
                'amount'          => 100.00,
                'dateTransaction' => self::ACC_DATE,
                'exchangeRate'    => 25.30,
            ]],
        ]));

        $rows = $this->txRows();
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(25.30, (float) $rows[0]['exchange_rate'], 0.0001);
        $this->assertEqualsWithDelta(100.00, (float) $rows[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(2530.00, (float) $rows[0]['amount_dom'], 0.001);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function txRows(): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_bank_transactions WHERE bank_account = %i ORDER BY date_transaction, id',
            $this->bankAccountId,
        );
        return array_map(static fn($r) => $r->toArray(), $rows);
    }

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
}
