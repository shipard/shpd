<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Bank;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Bank\BankTransactionAccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Banka Fáze 3 W6.3/W6.4 — lifecycle účtování transakce přes TableGateway
 * s reálným DocumentEventDispatcher: vstup do 40 generuje deník, výstup ho
 * maže (accounting_state 0), návrat negeneruje duplicitně, delete uklízí
 * (beforeDelete). W6.3 idempotence: opětovné přeúčtování nezdvojuje řádky.
 */
class BankAccountingLifecycleTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    private ?TableGateway $gateway = null;
    private ?ConfigRuntime $configRuntime = null;
    private ?int $txId = null;

    /** @var list<int> */
    private array $createdBankAccounts = [];
    /** @var list<int> */
    private array $createdAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months
             WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fm === null) {
            $this->markTestSkipped('DS nemá fiskální období pro ' . self::ACC_DATE);
        }

        $this->gateway = new TableGateway(
            'economy_bank_transactions',
            $this->db->getDibiConnection(),
            DocumentLoader::load($this->dsConfig, $resolver),
            $this->tables['economy_bank_transactions']->childTables,
            $this->configRuntime,
            $this->dsConfig,
            DocumentEventHandlerLoader::load(
                $this->dsConfig,
                $resolver,
                $this->db->getDibiConnection(),
                $this->configRuntime,
            ),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $dibi = $this->db->getDibiConnection();
            if ($this->txId !== null) {
                $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $this->txId)->execute();
                $dibi->delete('economy_bank_transactions')->where('id = %i', $this->txId)->execute();
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

    public function testFullLifecycle(): void
    {
        $baId = $this->setupBankAccount();

        // 1. Nová transakce (stav 10) — bez deníku
        $create = $this->gateway->saveDocument([
            'bank_account'      => $baId,
            'direction'         => 1,
            'amount'            => 1210.00,
            'currency'          => 'czk',
            'exchange_rate'     => 1,
            'date_transaction'  => self::ACC_DATE,
            'operation'         => 'payment.in',
            'counterparty_name' => 'IT lifecycle protistrana',
            'docState'          => 10,
            'docStateMain'      => 1,
        ]);
        $this->assertTrue($create->isSuccess(), 'Insert selhal: ' . ($create->getErrorMessage() ?? 'validace'));
        $this->txId = (int) $create->getData()['id'];
        $this->assertSame(0, $this->journalCount(), 'Nová transakce nesmí mít deník');

        // 2. → Zaúčtováno (40): vstup do 40 vygeneruje 2 řádky
        $this->transitionTo(40);
        $this->assertSame(2, $this->journalCount(), 'banka + clearing');
        $this->assertSame(1, (int) $this->txField('accounting_state'));

        // 3. → V opravě (80): deník zmizí, accounting_state 0
        $this->transitionTo(80);
        $this->assertSame(0, $this->journalCount());
        $this->assertSame(0, (int) $this->txField('accounting_state'));

        // 4. zpět → 40: deník znovu, nezdvojený
        $this->transitionTo(40);
        $this->assertSame(2, $this->journalCount());
        $this->assertSame(1, (int) $this->txField('accounting_state'));

        // 5. Delete ve stavu 40 — beforeDelete handler uklidí deník
        $delete = $this->gateway->deleteDocument($this->txId);
        $this->assertTrue($delete->isSuccess());
        $this->assertSame(0, $this->journalCount());
        $this->txId = null; // tearDown už nemá co mazat
    }

    public function testReaccountIsIdempotent(): void
    {
        $baId = $this->setupBankAccount();

        $create = $this->gateway->saveDocument([
            'bank_account'      => $baId,
            'direction'         => 2,
            'amount'            => 50.00,
            'currency'          => 'czk',
            'exchange_rate'     => 1,
            'date_transaction'  => self::ACC_DATE,
            'operation'         => 'fee.out',
            'counterparty_name' => 'IT idempotence',
            'docState'          => 10,
            'docStateMain'      => 1,
        ]);
        $this->assertTrue($create->isSuccess());
        $this->txId = (int) $create->getData()['id'];

        $this->transitionTo(40);
        $this->assertSame(2, $this->journalCount());

        // Opětovné přeúčtování (DELETE+INSERT) nezdvojuje řádky
        $engine = new BankTransactionAccountingEngine($this->db->getDibiConnection(), $this->configRuntime);
        $engine->accountTransaction($this->txId);
        $engine->accountTransaction($this->txId);
        $this->assertSame(2, $this->journalCount(), 'Opakované účtování nezdvojuje řádky');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function setupBankAccount(): int
    {
        $bankAccountId = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200'); // clearing pro payment.in
        $this->ensureAccountByMask('568');       // poplatek pro fee.out

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'IT' . substr(uniqid(), -8),
            'name'               => 'IT lifecycle bankovní účet',
            'currency'           => 'czk',
            'accounting_account' => $bankAccountId,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdBankAccounts[] = $id;
        return $id;
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

    private function transitionTo(int $state): void
    {
        $data = $this->gateway->loadDocument($this->txId);
        $this->assertNotNull($data);
        $data['docState'] = $state;
        $result = $this->gateway->saveDocument($data);
        $this->assertTrue(
            $result->isSuccess(),
            "Přechod do {$state} selhal: " . ($result->getErrorMessage() ?? 'validace'),
        );
    }

    private function journalCount(): int
    {
        $row = $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM economy_accounting_journal WHERE bank_transaction = %i',
            $this->txId,
        );
        return (int) $row['c'];
    }

    private function txField(string $col): mixed
    {
        $row = $this->db->fetchRow(
            "SELECT `{$col}` FROM economy_bank_transactions WHERE id = %i",
            $this->txId,
        );
        return $row[$col] ?? null;
    }
}
