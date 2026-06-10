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
                    'invno' => ['order' => 900], 'invni' => ['order' => 900],
                ]],
                'sale.goods'    => ['name' => 'Prodej zboží',  'docTypes' => ['invno' => ['order' => 200]]],
                'sale.services' => ['name' => 'Prodej služeb', 'docTypes' => ['invno' => ['order' => 100]]],
                'purchase.goods' => ['name' => 'Nákup zboží', 'docTypes' => ['invni' => ['order' => 100]]],
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

    private function dbWithHead(string $docType): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'doc_type'  => $docType,
            'vat_place' => 0,
            'vat_duzp'  => null,
            'vat_mode'  => 1,
            'vat_registration' => null,
        ]);
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function form(string $docType): DocRowsForm
    {
        $form = new DocRowsForm('docs_core_rows');
        $form->setConfig($this->buildConfig());
        $form->setDb($this->dbWithHead($docType));
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
            ['sale.services', 'sale.goods', 'acc.entry'],
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
}
