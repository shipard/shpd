<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormTab;

/** Validace a serializace tabu typu `subtable` — `orderColumn` (fáze 3). */
class FormTabTest extends TestCase
{
    public function testOrderColumnIsSerializedAsOrderColumn(): void
    {
        $tab = new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'formId' => null, 'sort' => null, 'orderColumn' => 'order_pos'],
        );
        $arr = $tab->toArray();
        $this->assertSame('order_pos', $arr['subtable']['order_column']);
        $this->assertArrayNotHasKey('sort', $arr['subtable']);
    }

    public function testWithoutOrderColumnKeyIsAbsent(): void
    {
        $tab = new FormTab(
            id: 'contacts',
            label: 'Kontakty',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'formId' => null, 'sort' => 'name:asc', 'orderColumn' => null],
        );
        $arr = $tab->toArray();
        $this->assertArrayNotHasKey('order_column', $arr['subtable']);
        $this->assertSame('name:asc', $arr['subtable']['sort']);
    }

    public function testOrderColumnCannotBeCombinedWithSort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined');
        new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'sort' => 'name:asc', 'orderColumn' => 'order_pos'],
        );
    }

    public function testEmptyOrderColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'orderColumn' => ''],
        );
    }
}
