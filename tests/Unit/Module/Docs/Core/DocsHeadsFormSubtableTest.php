<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\DocsHeadsForm;

/**
 * Sub-tabulka řádků dokladu (položková sada) — sloupce podle vat_mode
 * rodiče, formátování buněk, textový řádek, zkratky jednotek jedním dotazem.
 * Definice `docs_core_rows` se bere z reálného JSONC (lokalizace cs), aby
 * test hlídal, že použité id sloupců ve schématu existují.
 */
class DocsHeadsFormSubtableTest extends TestCase
{
    private const ROWS_TABLE = __DIR__ . '/../../../../../modules/docs/core/tables/docs_core_rows.jsonc';

    private function rowsTableDef(): TableDefinition
    {
        $raw = JsoncParser::parseFile(self::ROWS_TABLE);
        return TableDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'));
    }

    /** @param list<array<string, mixed>> $units */
    private function form(array $units = [], ?string &$sql = null): DocsHeadsForm
    {
        $form = new DocsHeadsForm('docs_core_heads');
        $form->setTables(['docs_core_rows' => $this->rowsTableDef()]);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($units, &$sql): array {
                $sql = (string) ($args[0] ?? '');
                return $units;
            },
        );
        $form->setDb($db);
        return $form;
    }

    private function rowsTab(): FormTab
    {
        return new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'docs_core_rows', 'foreignKey' => 'doc_head', 'formId' => 'docs.core.rows'],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function columnsById(array $result): array
    {
        return array_column($result['columns'], null, 'id');
    }

    public function testVatDocumentHasVatColumnsWithLocalizedLabels(): void
    {
        $form = $this->form([['id' => 3, 'shortcut' => 'ks', 'name' => 'kus']]);
        $rows = [
            ['id' => 101, 'doc_head' => 1, 'row_kind' => 1, 'order_pos' => 1, 'description' => 'Ukázková služba',
             'quantity' => '2.0000', 'unit' => 3, 'unit_price' => '1000.0000',
             'vat_base' => '2000.00', 'vat_pct' => '21.00', 'vat_amount' => '420.00', 'vat_total' => '2420.00',
             'total_price' => '2000.00'],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1]);

        $this->assertSame(
            ['order_pos', 'description', 'quantity', 'unit', 'unit_price', 'vat_base', 'vat_pct', 'vat_amount', 'vat_total'],
            array_column($result['columns'], 'id'),
        );
        $cols = $this->columnsById($result);
        $this->assertSame('#', $cols['order_pos']['label']);
        $this->assertSame('Popis', $cols['description']['label']);
        $this->assertTrue($cols['description']['grow']);
        $this->assertSame('Množství', $cols['quantity']['label']);
        $this->assertSame('right', $cols['quantity']['align']);
        $this->assertSame('DPH %', $cols['vat_pct']['label']);
        $this->assertSame('Celkem s DPH', $cols['vat_total']['label']);
        $this->assertArrayNotHasKey('align', $cols['unit']);
        $this->assertNull($result['order_column']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(101, $result['rows'][0]['id']);
        $this->assertSame([
            'order_pos'   => '1',
            'description' => 'Ukázková služba',
            'quantity'    => '2',
            'unit'        => 'ks',
            'unit_price'  => '1 000,00',
            'vat_base'    => '2 000,00',
            'vat_pct'     => '21',
            'vat_amount'  => '420,00',
            'vat_total'   => '2 420,00',
        ], $result['rows'][0]['cells']);
    }

    public function testNoVatDocumentDropsVatColumnsAndUsesTotalPrice(): void
    {
        $form = $this->form();
        $rows = [
            ['id' => 5, 'row_kind' => 1, 'order_pos' => 2, 'description' => 'Bez DPH', 'quantity' => '1.0000',
             'unit' => null, 'unit_price' => '150.5000', 'total_price' => '150.50', 'vat_pct' => '0.00'],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 0]);

        $ids = array_column($result['columns'], 'id');
        $this->assertSame(['order_pos', 'description', 'quantity', 'unit', 'unit_price', 'total_price'], $ids);
        $this->assertSame('Cena celkem', $this->columnsById($result)['total_price']['label']);
        $this->assertSame([
            'order_pos'   => '2',
            'description' => 'Bez DPH',
            'quantity'    => '1',
            'unit_price'  => '150,50',
            'total_price' => '150,50',
        ], $result['rows'][0]['cells']);
    }

    public function testTextRowIsMutedAndHasNoNumericCells(): void
    {
        $form = $this->form();
        $rows = [
            ['id' => 7, 'row_kind' => 0, 'order_pos' => 0, 'description' => 'Poznámka k dodávce',
             'quantity' => '0.0000', 'unit_price' => null, 'total_price' => '0.00', 'vat_total' => '0.00'],
            ['id' => 8, 'row_kind' => 1, 'order_pos' => 0, 'description' => 'Položka', 'quantity' => null,
             'unit_price' => null, 'vat_total' => null],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1]);

        // order_pos = 0 → pořadí v seznamu
        $this->assertSame(
            ['order_pos' => '1', 'description' => ['text' => 'Poznámka k dodávce', 'class' => 'muted']],
            $result['rows'][0]['cells'],
        );
        // prázdná čísla = chybějící klíče, žádné „0,00"
        $this->assertSame(['order_pos' => '2', 'description' => 'Položka'], $result['rows'][1]['cells']);
    }

    public function testUnitsAreLoadedWithSingleInQuery(): void
    {
        $sql = null;
        $form = $this->form([
            ['id' => 3, 'shortcut' => 'ks', 'name' => 'kus'],
            ['id' => 4, 'shortcut' => '', 'name' => 'hodina'],
        ], $sql);
        $rows = [
            ['id' => 1, 'row_kind' => 1, 'unit' => 3],
            ['id' => 2, 'row_kind' => 1, 'unit' => 4],
            ['id' => 3, 'row_kind' => 1, 'unit' => 3],
            ['id' => 4, 'row_kind' => 1, 'unit' => 99],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1]);

        $this->assertStringContainsString('FROM `core_units` WHERE `id` IN %in', $sql);
        $this->assertSame('ks', $result['rows'][0]['cells']['unit']);
        // bez zkratky padá na název
        $this->assertSame('hodina', $result['rows'][1]['cells']['unit']);
        $this->assertSame('ks', $result['rows'][2]['cells']['unit']);
        $this->assertArrayNotHasKey('unit', $result['rows'][3]['cells']);
    }

    public function testWithoutDbUnitsAreSimplyMissing(): void
    {
        $form = new DocsHeadsForm('docs_core_heads');
        $result = $form->renderSubtable(
            $this->rowsTab(),
            [['id' => 1, 'row_kind' => 1, 'unit' => 3, 'description' => 'x']],
            ['id' => 1, 'vat_mode' => 1],
        );
        $this->assertArrayNotHasKey('unit', $result['rows'][0]['cells']);
        // bez registru tabulek platí české fallback labely
        $this->assertSame('Popis', $this->columnsById($result)['description']['label']);
    }

    public function testOtherSubtableTabsFallBackToDefaultRenderer(): void
    {
        $form = $this->form();
        $tab = new FormTab(
            id: 'other',
            label: 'Other',
            type: 'subtable',
            subtable: ['table' => 'docs_core_rows', 'foreignKey' => 'doc_head'],
        );
        $result = $form->renderSubtable($tab, [], ['id' => 1]);
        // default renderer nad definicí docs_core_rows: první sloupce schématu bez FK/PK
        $this->assertSame('row_kind', $result['columns'][0]['id']);
        $this->assertCount(6, $result['columns']);
    }
}
