<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end lifecycle účtování („Hotovo když" bod 2 a 5 z
 * tasks/accounting-phase2.md): doklad přes TableGateway s reálným
 * DocumentEventDispatcher — vstup do 40 generuje deník, výstup ho maže,
 * návrat negeneruje duplicitně, delete uklízí.
 */
class AccountingLifecycleTest extends IntegrationTestCase
{
    private ?TableGateway $gateway = null;
    private ?int $headId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $own = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE is_own = 1 AND docState IN (10, 40, 80) LIMIT 1',
        );
        if ($own === null) {
            $this->markTestSkipped('Dev DS nemá nastavenou vlastní firmu');
        }

        $this->gateway = new TableGateway(
            'docs_core_heads',
            $this->db->getDibiConnection(),
            DocumentLoader::load($this->dsConfig, $resolver),
            $this->tables['docs_core_heads']->childTables,
            $configRuntime,
            $this->dsConfig,
            DocumentEventHandlerLoader::load(
                $this->dsConfig,
                $resolver,
                $this->db->getDibiConnection(),
                $configRuntime,
            ),
        );
    }

    protected function tearDown(): void
    {
        if ($this->headId !== null) {
            $dibi = $this->db->getDibiConnection();
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $this->headId)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $this->headId)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $this->headId)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $this->headId)->execute();
        }
        parent::tearDown();
    }

    private function journalCount(): int
    {
        $row = $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM economy_accounting_journal WHERE doc_head = %i',
            $this->headId,
        );
        return (int) $row['c'];
    }

    private function headField(string $col): mixed
    {
        $row = $this->db->fetchRow(
            "SELECT `{$col}` FROM docs_core_heads WHERE id = %i",
            $this->headId,
        );
        return $row[$col] ?? null;
    }

    private function transitionTo(int $state): void
    {
        $data = $this->gateway->loadDocument($this->headId);
        $this->assertNotNull($data);
        $data['docState'] = $state;
        $result = $this->gateway->saveDocument($data);
        $this->assertTrue(
            $result->isSuccess(),
            "Přechod do {$state} selhal: " . ($result->getErrorMessage() ?? 'validace'),
        );
    }

    public function testFullLifecycle(): void
    {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', 'invno',
        );
        $partner = $this->db->fetchRow('SELECT id FROM base_persons_persons ORDER BY id LIMIT 1');
        $vatReg  = $this->db->fetchRow('SELECT id FROM economy_codebooks_vat_registrations LIMIT 1');
        // FVB vyžaduje při potvrzení bankovní účet vlastní firmy
        $bankAcc = $this->db->fetchRow(
            'SELECT ba.id FROM base_persons_bank_accounts ba
             JOIN base_persons_persons p ON p.id = ba.person AND p.is_own = 1
             LIMIT 1',
        );
        if ($series === null || $partner === null || $vatReg === null || $bankAcc === null) {
            $this->markTestSkipped('Dev DS nemá řadu invno / osobu / registraci DPH / bankovní účet');
        }

        // 1. Vytvoř fakturu v Konceptu se dvěma řádky služeb — compute
        //    pipeline dopočítá ceny, DPH, recap, totals i _dom.
        $create = $this->gateway->saveDocument([
            'doc_type'         => 'invno',
            'number_series'    => (int) $series['id'],
            'issue_date'       => '2026-06-10',
            'accounting_date'  => '2026-06-10',
            'partner'          => (int) $partner['id'],
            'vat_registration' => (int) $vatReg['id'],
            'bank_account'     => (int) $bankAcc['id'],
            'vat_mode'         => 1,
            'doc_text'         => 'IT lifecycle test',
            'docState'         => 10,
            'docStateMain'     => 1,
            'rows'             => [
                ['row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Práce A',
                 'quantity' => 1, 'unit_price' => 1000.0, 'vat_code' => 'cz-120', 'vat_pct' => 21.0],
                ['row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Práce B',
                 'quantity' => 2, 'unit_price' => 250.0, 'vat_code' => 'cz-120', 'vat_pct' => 21.0],
            ],
        ]);
        $this->assertTrue($create->isSuccess(), 'Insert selhal: ' . ($create->getErrorMessage() ?? 'validace'));
        $this->headId = (int) $create->getData()['id'];
        $this->assertSame(0, $this->journalCount(), 'Koncept nesmí mít deník');

        // 2. Koncept → Vystaveno → V pořádku: vstup do 40 zaúčtuje
        $this->transitionTo(20);
        $this->assertSame(0, $this->journalCount());

        $this->transitionTo(40);
        $this->assertSame(3, $this->journalCount(), '602 + 343 + 311');
        $this->assertSame(1, (int) $this->headField('accounting_state'));

        $journal = $this->db->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i', $this->headId,
        );
        $dr = 0.0;
        $cr = 0.0;
        foreach ($journal as $line) {
            $dr += (float) $line['money_dr'];
            $cr += (float) $line['money_cr'];
        }
        $this->assertEqualsWithDelta($dr, $cr, 0.001);
        $this->assertEqualsWithDelta(1815.0, $dr, 0.001); // 1500 + 315 DPH

        // 3. V pořádku → V opravě: deník zmizí, stav 0
        $this->transitionTo(80);
        $this->assertSame(0, $this->journalCount());
        $this->assertSame(0, (int) $this->headField('accounting_state'));

        // 4. Zpět do 40: deník znovu, nezdvojený
        $this->transitionTo(40);
        $this->assertSame(3, $this->journalCount());
        $this->assertSame(1, (int) $this->headField('accounting_state'));

        // 5. Delete dokladu ve 40 — beforeDelete handler uklidí deník
        $delete = $this->gateway->deleteDocument($this->headId);
        $this->assertTrue($delete->isSuccess());
        $this->assertSame(0, $this->journalCount());
        $this->headId = null; // tearDown už nemá co mazat
    }
}
