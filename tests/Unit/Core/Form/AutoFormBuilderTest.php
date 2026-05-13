<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\AutoFormBuilder;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormTab;

class AutoFormBuilderTest extends TestCase
{
    private function makeTableDef(array $columns, array $columnGroups = []): TableDefinition
    {
        $hasPk = false;
        foreach ($columns as $col) {
            if ($col['primaryKey'] ?? false) {
                $hasPk = true;
                break;
            }
        }
        if (!$hasPk) {
            array_unshift($columns, [
                'id' => 'id', 'name' => 'ID', 'type' => 'int',
                'autoIncrement' => true, 'primaryKey' => true,
            ]);
        }

        return TableDefinition::fromArray([
            'tableId'      => 100,
            'name'         => 'Test Table',
            'columns'      => $columns,
            'columnGroups' => $columnGroups,
        ]);
    }

    /** @return FormElement[] */
    private function elementsOf(FormTab $tab): array
    {
        $this->assertSame('fields', $tab->type);
        $this->assertCount(1, $tab->sections, 'auto-built tab should have exactly one section');
        $this->assertCount(1, $tab->sections[0]->columns, 'auto-built section should have exactly one column');
        return $tab->sections[0]->columns[0]->elements;
    }

    public function testGroupedColumnsBecomeTabs(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100, 'group' => 'basic'],
                ['id' => 'email', 'name' => 'Email', 'type' => 'varchar', 'length' => 200, 'group' => 'contact'],
            ],
            columnGroups: [
                ['id' => 'basic', 'name' => 'Basic Info'],
                ['id' => 'contact', 'name' => 'Contact'],
            ],
        );

        $result = (new AutoFormBuilder())->build($def, tableId: 'test_table');

        $this->assertSame('test_table', $result->table);
        $this->assertCount(2, $result->tabs);
        $this->assertSame('basic', $result->tabs[0]->id);
        $this->assertSame('Basic Info', $result->tabs[0]->label);
        $this->assertSame('contact', $result->tabs[1]->id);
    }

    public function testUngroupedColumnsGoToGeneralTab(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
                ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 10],
            ],
        );

        $result = (new AutoFormBuilder())->build($def);

        $this->assertCount(1, $result->tabs);
        $this->assertSame('general', $result->tabs[0]->id);
        $this->assertSame('General', $result->tabs[0]->label);
        $this->assertCount(2, $this->elementsOf($result->tabs[0]));
    }

    public function testSingleSectionSingleColumnStructure(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'a', 'name' => 'A', 'type' => 'varchar', 'length' => 50],
            ['id' => 'b', 'name' => 'B', 'type' => 'varchar', 'length' => 50],
            ['id' => 'c', 'name' => 'C', 'type' => 'text'],
        ]);

        $result = (new AutoFormBuilder())->build($def);
        $section = $result->tabs[0]->sections[0];

        $this->assertNull($section->title);
        $this->assertCount(1, $section->columns);
        $this->assertCount(3, $section->columns[0]->elements);
    }

    public function testSystemColumnsSkipped(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
            ['id' => 'docState', 'name' => 'State', 'type' => 'tinyint', 'system' => true],
            ['id' => 'docStateMain', 'name' => 'Main State', 'type' => 'tinyint', 'system' => true],
        ]);

        $result = (new AutoFormBuilder())->build($def);
        $cols = array_map(fn($e) => $e->column, $this->elementsOf($result->tabs[0]));

        $this->assertContains('name', $cols);
        $this->assertNotContains('docState', $cols);
        $this->assertNotContains('docStateMain', $cols);
    }

    public function testIdCreatedModifiedSkipped(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
            ['id' => 'created', 'name' => 'Created', 'type' => 'datetime'],
            ['id' => 'modified', 'name' => 'Modified', 'type' => 'datetime'],
        ]);

        $result = (new AutoFormBuilder())->build($def);
        $cols = array_map(fn($e) => $e->column, $this->elementsOf($result->tabs[0]));

        $this->assertContains('name', $cols);
        $this->assertNotContains('id', $cols);
        $this->assertNotContains('created', $cols);
        $this->assertNotContains('modified', $cols);
    }

    public function testPasswordColumnsSkipped(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 50],
            ['id' => 'password_hash', 'name' => 'Password', 'type' => 'varchar', 'length' => 255],
        ]);

        $result = (new AutoFormBuilder())->build($def);
        $cols = array_map(fn($e) => $e->column, $this->elementsOf($result->tabs[0]));

        $this->assertContains('login', $cols);
        $this->assertNotContains('password_hash', $cols);
    }

    public function testInputTypeDerivedFromColumn(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'name',  'name' => 'N', 'type' => 'varchar', 'length' => 50],
            ['id' => 'notes', 'name' => 'X', 'type' => 'text'],
            ['id' => 'qty',   'name' => 'Q', 'type' => 'int'],
            ['id' => 'birth', 'name' => 'B', 'type' => 'date'],
            ['id' => 'active','name' => 'A', 'type' => 'boolean'],
        ]);
        $result = (new AutoFormBuilder())->build($def);
        $byCol = [];
        foreach ($this->elementsOf($result->tabs[0]) as $el) {
            $byCol[$el->column] = $el;
        }

        $this->assertSame('text', $byCol['name']->inputType);
        $this->assertSame('textarea', $byCol['notes']->inputType);
        $this->assertSame('number', $byCol['qty']->inputType);
        $this->assertSame('date', $byCol['birth']->inputType);
        $this->assertSame('checkbox', $byCol['active']->inputType);
    }

    public function testEnumIntBecomesSelectWithOptions(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'person_type', 'name' => 'Type', 'type' => 'enumInt', 'cfgItem' => 'base.persons.personTypes'],
        ]);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')
            ->willReturnCallback(fn(string $id) => match ($id) {
                'base.persons.personTypes' => [
                    '0' => ['name' => 'Undefined'],
                    '1' => ['name' => 'Person'],
                    '2' => ['name' => 'Company'],
                ],
                default => null,
            });

        $result = (new AutoFormBuilder())->build($def, $config);
        $el = $this->elementsOf($result->tabs[0])[0];

        $this->assertSame('select', $el->type);
        $this->assertCount(3, $el->options);
        $this->assertSame(0, $el->options[0]['value']);
        $this->assertSame('Undefined', $el->options[0]['label']);
    }

    public function testEnumIntWithoutConfigHasEmptyOptions(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'person_type', 'name' => 'Type', 'type' => 'enumInt', 'cfgItem' => 'base.persons.personTypes'],
        ]);

        $result = (new AutoFormBuilder())->build($def, null);
        $el = $this->elementsOf($result->tabs[0])[0];

        $this->assertSame('select', $el->type);
        $this->assertSame([], $el->options);
    }

    public function testRequiredForNonNullableWithoutDefault(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50, 'nullable' => false],
            ['id' => 'email', 'name' => 'Email', 'type' => 'varchar', 'length' => 200, 'nullable' => true],
            ['id' => 'status', 'name' => 'Status', 'type' => 'int', 'default' => 0],
        ]);

        $result = (new AutoFormBuilder())->build($def);
        $elByCol = [];
        foreach ($this->elementsOf($result->tabs[0]) as $el) {
            $elByCol[$el->column] = $el;
        }

        $this->assertTrue($elByCol['name']->required);
        $this->assertFalse($elByCol['email']->required);
        $this->assertFalse($elByCol['status']->required);
    }

    public function testFullSizeDefaultsFalse(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
        ]);
        $this->assertFalse((new AutoFormBuilder())->build($def)->fullSize);
    }

    public function testDocStatesIsNullByDefault(): void
    {
        $def = $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
        ]);
        $this->assertNull((new AutoFormBuilder())->build($def)->docStates);
    }

    public function testGeneralTabComesFirst(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'grouped', 'name' => 'Grouped', 'type' => 'varchar', 'length' => 50, 'group' => 'info'],
                ['id' => 'ungrouped', 'name' => 'Ungrouped', 'type' => 'varchar', 'length' => 50],
            ],
            columnGroups: [['id' => 'info', 'name' => 'Info']],
        );

        $result = (new AutoFormBuilder())->build($def);

        $this->assertCount(2, $result->tabs);
        $this->assertSame('general', $result->tabs[0]->id);
        $this->assertSame('info', $result->tabs[1]->id);
    }
}
