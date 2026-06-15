<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Bank;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Bank\BankStatementsViewer;
use Shipard\Module\Economy\Bank\BankTransactionsViewer;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Fáze 1 banky: lze vložit transakci/výpis (vč. dvou NULL fingerprintů bez
 * kolize unikátního indexu) a viewery zobrazí seznam, detail a stavové taby
 * a NENABÍZÍ vytvoření nového záznamu.
 */
class BankPhase1Test extends IntegrationTestCase
{
    private ?ConfigRuntime $configRuntime = null;
    /** @var int[] */
    private array $txIds = [];
    /** @var int[] */
    private array $stIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $dibi = $this->db->getDibiConnection();
            foreach ($this->txIds as $id) {
                $dibi->delete('economy_bank_transactions')->where('id = %i', $id)->execute();
            }
            foreach ($this->stIds as $id) {
                $dibi->delete('economy_bank_statements')->where('id = %i', $id)->execute();
            }
        }
        parent::tearDown();
    }

    private function txViewer(): BankTransactionsViewer
    {
        $v = new BankTransactionsViewer($this->db, 'economy_bank_transactions');
        $v->setConfig($this->configRuntime);
        $v->setLanguage('cs');
        return $v;
    }

    private function stViewer(): BankStatementsViewer
    {
        $v = new BankStatementsViewer($this->db, 'economy_bank_statements');
        $v->setConfig($this->configRuntime);
        $v->setLanguage('cs');
        return $v;
    }

    public function testInsertTransactionsAndViewer(): void
    {
        $dibi = $this->db->getDibiConnection();
        $bankAccount = 990001; // app-level FK, žádný DB constraint — stačí int

        // Dvě transakce se stejným účtem a NULL external_id/fingerprint:
        // MariaDB povoluje víc NULL v unikátním indexu → nesmí kolidovat.
        $dibi->insert('economy_bank_transactions', [
            'bank_account'     => $bankAccount,
            'direction'        => 1,
            'amount'           => 1210.00,
            'currency'         => 'czk',
            'amount_dom'       => 1210.00,
            'exchange_rate'    => 1,
            'date_transaction' => '2026-06-10',
            'counterparty_name' => 'IT BankPhase1 Alpha',
            'symbol1'          => '12345',
            'accounting_state' => 0,
            'docState'         => 10,
            'docStateMain'     => 1,
        ])->execute();
        $id1 = (int) $dibi->getInsertId();
        $this->txIds[] = $id1;

        $dibi->insert('economy_bank_transactions', [
            'bank_account'     => $bankAccount,
            'direction'        => 2,
            'amount'           => 50.00,
            'currency'         => 'czk',
            'amount_dom'       => 50.00,
            'exchange_rate'    => 1,
            'date_transaction' => '2026-06-11',
            'counterparty_name' => 'IT BankPhase1 Beta',
            'accounting_state' => 0,
            'docState'         => 10,
            'docStateMain'     => 1,
        ])->execute();
        $id2 = (int) $dibi->getInsertId();
        $this->txIds[] = $id2;

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);
        $this->assertNotSame($id1, $id2, 'Dvě transakce s NULL fingerprintem nesmí kolidovat');

        $viewer = $this->txViewer();

        // Stavové taby z economy.bank.txStates
        $this->assertContains('active', $viewer->getViewGroups());

        // Seznam vrátí obě transakce
        $rows = $viewer->selectRows(null, [], 0);
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);

        // renderRow: směr drží znaménko, stav = concept (docState 10)
        $row1 = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $id1) {
                $row1 = $r;
            }
        }
        $this->assertNotNull($row1);
        $rendered = $viewer->renderRow($row1);
        $this->assertSame('IT BankPhase1 Alpha', $rendered['t1']);
        $this->assertSame('concept', $rendered['stateStyle']);
        $this->assertStringStartsWith('+', (string) $rendered['i1'][0]['text']);

        // detail má taby a skupiny vlastností
        $detail = $viewer->renderDetail($id1);
        $this->assertNotEmpty($detail['tabs']);
        $this->assertNotEmpty($detail['tabs'][0]['content']['groups']);

        // Žádné „nový"; edit jen na vybraném řádku
        $this->assertSame([], $viewer->getToolbarActions(null));
        $actions = $viewer->getToolbarActions($row1);
        $actionIds = array_column($actions, 'id');
        $this->assertContains('edit', $actionIds);
        $this->assertNotContains('create', $actionIds);
    }

    public function testInsertStatementAndViewer(): void
    {
        $dibi = $this->db->getDibiConnection();

        $dibi->insert('economy_bank_statements', [
            'bank_account'         => 990001,
            'statement_number'     => 'IT-2026-06',
            'period_start'         => '2026-06-01',
            'period_end'           => '2026-06-30',
            'opening_balance'      => 1000.00,
            'closing_balance'      => 2160.00,
            'currency'             => 'czk',
            'reconciliation_state' => 0,
            'docState'             => 10,
            'docStateMain'         => 1,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->stIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $viewer = $this->stViewer();

        $this->assertContains('active', $viewer->getViewGroups());

        $rows = $viewer->selectRows(null, [], 0);
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $this->assertContains($id, $ids);

        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $id) {
                $row = $r;
            }
        }
        $this->assertNotNull($row);
        $rendered = $viewer->renderRow($row);
        $this->assertSame('IT-2026-06', $rendered['t1']);

        $detail = $viewer->renderDetail($id);
        $this->assertNotEmpty($detail['tabs']);
        $this->assertNotEmpty($detail['tabs'][0]['content']['groups']);

        $this->assertSame([], $viewer->getToolbarActions(null));
        $actionIds = array_column($viewer->getToolbarActions($row), 'id');
        $this->assertContains('edit', $actionIds);
        $this->assertNotContains('create', $actionIds);
    }
}
