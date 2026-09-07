<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\DocRowsForm;

/**
 * Select pohybu v řádkovém sub-formu: options filtrované podle doc_type
 * hlavičky, řazené dle order, default = nejnižší order, skryté pro
 * textové řádky.
 */
class DocRowsFormOperationsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_rowops_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'docs.core.rowOperations' => [
                // schválně přeházené pořadí — řadí se podle order, ne klíče
                'acc.entry' => ['name' => 'Účetní položka', 'docTypes' => [
                    'invno' => ['order' => 900], 'invni' => ['order' => 900], 'cash' => ['order' => 900],
                ]],
                'sale.goods'    => ['name' => 'Prodej zboží',  'docTypes' => [
                    'invno' => ['order' => 200], 'cash' => ['order' => 200, 'cashDir' => 1],
                ]],
                'sale.services' => ['name' => 'Prodej služeb', 'docTypes' => [
                    'invno' => ['order' => 100], 'cash' => ['order' => 100, 'cashDir' => 1],
                ]],
                'purchase.goods' => ['name' => 'Nákup zboží', 'docTypes' => [
                    'invni' => ['order' => 100], 'cash' => ['order' => 100, 'cashDir' => 2],
                ]],
                'payment.receivable' => [
                    'name' => 'Úhrada pohledávky',
                    'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'identityRequired' => 1,
                    'docTypes' => ['cash' => ['order' => 300, 'cashDir' => 1]],
                ],
                'payment.payable' => [
                    'name' => 'Úhrada závazku',
                    'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'identityRequired' => 1,
                    'docTypes' => ['cash' => ['order' => 400, 'cashDir' => 2]],
                ],
                'transfer.in' => [
                    'name' => 'Příjem z převodu peněz', 'rowSide' => 0, 'rowPaymentId' => 1,
                    'docTypes' => ['cash' => ['order' => 350, 'cashDir' => 1]],
                ],
                'transfer.out' => [
                    'name' => 'Výdej pro převod peněz', 'rowSide' => 0, 'rowPaymentId' => 1,
                    'docTypes' => ['cash' => ['order' => 450, 'cashDir' => 2]],
                ],
                // zálohy (Task E): odpočet položkový s DPH jako na faktuře, hotovostní záloha kontační
                'sale.advanceDeduction' => [
                    'name' => 'Odpočet přijaté zálohy', 'rowPaymentId' => 1,
                    'docTypes' => ['invno' => ['order' => 300], 'cash' => ['order' => 360, 'cashDir' => 1]],
                ],
                'advance.received' => [
                    'name' => 'Přijatá záloha', 'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'partnerRequired' => 1,
                    'docTypes' => ['cash' => ['order' => 370, 'cashDir' => 1]],
                ],
            ],
            'docs.core.docTypes' => [
                'invno' => ['trade_dir' => 1],
                'invni' => ['trade_dir' => 2],
                'cash'  => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            ],
            'docs.core.rowKinds' => [
                '0' => ['name' => 'Textový řádek'],
                '1' => ['name' => 'Běžný řádek'],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => $items]),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    private function dbWithHead(string $docType, int $cashDir = 0): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'doc_type'  => $docType,
            'cash_dir'  => $cashDir,
            'vat_place' => 0,
            'vat_duzp'  => null,
            'vat_mode'  => 1,
            'vat_registration' => null,
        ]);
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function form(string $docType, int $cashDir = 0): DocRowsForm
    {
        $form = new DocRowsForm('docs_core_rows');
        $form->setConfig($this->buildConfig());
        $form->setDb($this->dbWithHead($docType, $cashDir));
        return $form;
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->column === $column) {
                        return $el;
                    }
                }
            }
        }
        return null;
    }

    public function testOptionsFilteredByDocTypeAndSortedByOrder(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $def = $this->form('invno')->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertNotNull($el);
        $this->assertSame(
            ['sale.services', 'sale.goods', 'sale.advanceDeduction', 'acc.entry'],
            array_column($el->options, 'value'),
        );
    }

    public function testOptionsForReceivedInvoice(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $def = $this->form('invni')->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertSame(
            ['purchase.goods', 'acc.entry'],
            array_column($el->options, 'value'),
        );
    }

    public function testNewRowGetsDefaultOperationWithLowestOrder(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->form('invno')->applyNewRecordDefaults($data);

        $this->assertSame('sale.services', $data['operation']);
    }

    public function testNewRowDefaultKeepsExplicitPrefill(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'sale.goods'];
        $this->form('invno')->applyNewRecordDefaults($data);

        $this->assertSame('sale.goods', $data['operation']);
    }

    public function testTextRowHidesOperationAndGetsNoDefault(): void
    {
        $data = ['row_kind' => 0, 'doc_head' => 5];
        $this->form('invno')->applyNewRecordDefaults($data);
        $def = $this->form('invno')->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertTrue($el->hidden);
        $this->assertArrayNotHasKey('operation', $data);
    }

    public function testRecalculateRowKindToTextClearsOperation(): void
    {
        $data = ['row_kind' => 0, 'doc_head' => 5, 'operation' => 'sale.services', 'id' => 9];
        $result = $this->form('invno')->recalculate('row_kind', $data);

        $this->assertNull($result->data['operation']);
    }

    public function testRecalculateRowKindToStandardFillsDefault(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => null, 'id' => 9];
        $result = $this->form('invno')->recalculate('row_kind', $data);

        $this->assertSame('sale.services', $result->data['operation']);
    }

    // ── Pokladní doklad: pohyby dle cash_dir ────────────────────────────────

    public function testCashReceiptOffersOnlyReceiptOperations(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertSame(
            ['sale.services', 'sale.goods', 'payment.receivable', 'transfer.in', 'sale.advanceDeduction', 'advance.received', 'acc.entry'],
            array_column($el->options, 'value'),
        );
    }

    /**
     * Zálohy na pokladním dokladu (Task E): odpočet zálohy se chová jako na
     * faktuře — položkový řádek s DPH; hotovostní záloha je kontační bez DPH
     * s partnerem (povinným) a nepovinným VS.
     */
    public function testAdvanceDeductionOnCashKeepsItemLayoutWithVat(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'sale.advanceDeduction'];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $this->assertNotNull($this->findElement($def, 'item'), 'položkový layout');
        $this->assertNotNull($this->findElement($def, 'quantity'));
        $this->assertNotNull($this->findElement($def, 'vat_code'), 'odpočet zdaněné zálohy nese DPH');
        $this->assertNotNull($this->findElement($def, 'payment_reference'), 'číslo zálohového dokladu');
        $this->assertNull($this->findElement($def, 'partner'), 'odpočet partnera řádku nemá');
        $this->assertNull($this->findElement($def, 'acc_side'));
    }

    public function testCashAdvanceUsesContationLayoutWithPartner(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'advance.received'];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $this->assertNull($this->findElement($def, 'vat_code'), 'záloha v hotovosti je bez DPH');
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'item'));
        $this->assertNull($this->findElement($def, 'acc_side'), 'stranu nese krok předpisu');
        $this->assertNotNull($this->findElement($def, 'total_price'));
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertFalse($this->findElement($def, 'payment_reference')->required, 'VS nepovinný');
    }

    public function testCashDisbursementOffersPurchaseOpsAndDefaultsToLowestOrder(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $form = $this->form('cash', cashDir: 2);
        $def = $form->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertSame(
            ['purchase.goods', 'payment.payable', 'transfer.out', 'acc.entry'],
            array_column($el->options, 'value'),
        );

        $form->applyNewRecordDefaults($data);
        $this->assertSame('purchase.goods', $data['operation']);
    }

    public function testPaymentRowUsesContationLayoutWithoutVatOrSide(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'payment.receivable'];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $this->assertNull($this->findElement($def, 'vat_code'), 'úhrada je bez DPH bloku');
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'acc_side'), 'stranu nese krok předpisu');
        $this->assertNotNull($this->findElement($def, 'total_price'));
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertNotNull($this->findElement($def, 'payment_reference'));
        $this->assertTrue($this->findElement($def, 'price_calc_mode')->hidden);
    }

    /**
     * Převod peněz (Task D): kontační layout jako úhrada, ale bez partnera
     * (žádný rowPartner) — partnera řídí výhradně vlajka, ne rowSide: 0.
     * payment_reference je nabídnutý, ne povinný.
     */
    public function testTransferRowUsesContationLayoutWithoutPartnerOrVat(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'transfer.out'];
        $def = $this->form('cash', cashDir: 2)->buildFormDefinition($data, true);

        $this->assertNull($this->findElement($def, 'vat_code'), 'převod je bez DPH bloku');
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'item'), 'převod je bez položky');
        $this->assertNull($this->findElement($def, 'acc_side'), 'stranu nese krok předpisu');
        $this->assertNull($this->findElement($def, 'partner'), 'převod nemá partnera (T3)');
        $this->assertTrue($this->findElement($def, 'total_price')->required);
        $ref = $this->findElement($def, 'payment_reference');
        $this->assertNotNull($ref, 'identifikace protistrany převodu');
        $this->assertFalse($ref->required, 'payment_reference u převodu nepovinný');
    }
}
