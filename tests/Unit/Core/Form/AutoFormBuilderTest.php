<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\AutoFormBuilder;

class AutoFormBuilderTest extends TestCase
{
    private function makeTableDef(array $columns, array $columnGroups = []): TableDefinition
    {
        // Always need a PK column
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

        $builder = new AutoFormBuilder();
        $result = $builder->build($def, tableId: 'test_table');

        $this->assertSame('test_table', $result->table);
        $this->assertCount(2, $result->tabs);
        $this->assertSame('basic', $result->tabs[0]->id);
        $this->assertSame('Basic Info', $result->tabs[0]->label);
        $this->assertSame('contact', $result->tabs[1]->id);
        $this->assertSame('Contact', $result->tabs[1]->label);
    }

    public function testUngroupedColumnsGoToGeneralTab(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
                ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 10],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $this->assertCount(1, $result->tabs);
        $this->assertSame('general', $result->tabs[0]->id);
        $this->assertSame('Obecné', $result->tabs[0]->label);
        $this->assertCount(2, $result->tabs[0]->elements);
    }

    public function testSystemColumnsSkipped(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
                ['id' => 'docState', 'name' => 'State', 'type' => 'tinyint', 'system' => true],
                ['id' => 'docStateMain', 'name' => 'Main State', 'type' => 'tinyint', 'system' => true],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        // Only 'name' should appear (system columns skipped, plus id is skipped)
        $allColumns = [];
        foreach ($result->tabs as $tab) {
            foreach ($tab->elements as $el) {
                if ($el->column !== null) {
                    $allColumns[] = $el->column;
                }
            }
        }

        $this->assertContains('name', $allColumns);
        $this->assertNotContains('docState', $allColumns);
        $this->assertNotContains('docStateMain', $allColumns);
    }

    public function testIdCreatedModifiedSkipped(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
                ['id' => 'created', 'name' => 'Created', 'type' => 'datetime'],
                ['id' => 'modified', 'name' => 'Modified', 'type' => 'datetime'],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $allColumns = [];
        foreach ($result->tabs as $tab) {
            foreach ($tab->elements as $el) {
                if ($el->column !== null) {
                    $allColumns[] = $el->column;
                }
            }
        }

        $this->assertContains('name', $allColumns);
        $this->assertNotContains('id', $allColumns);
        $this->assertNotContains('created', $allColumns);
        $this->assertNotContains('modified', $allColumns);
    }

    public function testPasswordColumnsSkipped(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 50],
                ['id' => 'password_hash', 'name' => 'Password', 'type' => 'varchar', 'length' => 255],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $allColumns = [];
        foreach ($result->tabs as $tab) {
            foreach ($tab->elements as $el) {
                if ($el->column !== null) {
                    $allColumns[] = $el->column;
                }
            }
        }

        $this->assertContains('login', $allColumns);
        $this->assertNotContains('password_hash', $allColumns);
    }

    public function testColsMappingVarcharShort(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 10],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $el = $result->tabs[0]->elements[0];
        $this->assertSame(1, $el->cols);
    }

    public function testColsMappingVarcharMedium(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $el = $result->tabs[0]->elements[0];
        $this->assertSame(2, $el->cols);
    }

    public function testColsMappingText(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'notes', 'name' => 'Notes', 'type' => 'text'],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $el = $result->tabs[0]->elements[0];
        $this->assertSame(4, $el->cols);
    }

    public function testColsMappingLongtext(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'body', 'name' => 'Body', 'type' => 'longtext'],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $el = $result->tabs[0]->elements[0];
        $this->assertSame(4, $el->cols);
    }

    public function testColsMappingOtherTypes(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'amount', 'name' => 'Amount', 'type' => 'int'],
                ['id' => 'active', 'name' => 'Active', 'type' => 'boolean'],
                ['id' => 'birth_date', 'name' => 'Birth', 'type' => 'date'],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        foreach ($result->tabs[0]->elements as $el) {
            $this->assertSame(1, $el->cols, "Column {$el->column} should have cols=1");
        }
    }

    public function testEnumIntBecomesSelectWithOptions(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'person_type', 'name' => 'Type', 'type' => 'enumInt', 'cfgItem' => 'base.persons.personTypes'],
            ],
        );

        // Mock ConfigRuntime
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')
            ->with('base.persons.personTypes')
            ->willReturn([
                '0' => ['name' => 'Undefined'],
                '1' => ['name' => 'Person'],
                '2' => ['name' => 'Company'],
            ]);

        $builder = new AutoFormBuilder();
        $result = $builder->build($def, $config);

        $el = $result->tabs[0]->elements[0];
        $this->assertSame('select', $el->type);
        $this->assertCount(3, $el->options);
        $this->assertSame(0, $el->options[0]['value']);
        $this->assertSame('Undefined', $el->options[0]['label']);
        $this->assertSame(1, $el->options[1]['value']);
        $this->assertSame(2, $el->options[2]['value']);
    }

    public function testEnumIntWithoutConfigHasEmptyOptions(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'person_type', 'name' => 'Type', 'type' => 'enumInt', 'cfgItem' => 'base.persons.personTypes'],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def, null);

        $el = $result->tabs[0]->elements[0];
        $this->assertSame('select', $el->type);
        $this->assertSame([], $el->options);
    }

    public function testRequiredForNonNullableWithoutDefault(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50, 'nullable' => false],
                ['id' => 'email', 'name' => 'Email', 'type' => 'varchar', 'length' => 200, 'nullable' => true],
                ['id' => 'status', 'name' => 'Status', 'type' => 'int', 'default' => 0],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $elements = $result->tabs[0]->elements;
        $elByCol = [];
        foreach ($elements as $el) {
            $elByCol[$el->column] = $el;
        }

        $this->assertTrue($elByCol['name']->required);
        $this->assertFalse($elByCol['email']->required);
        $this->assertFalse($elByCol['status']->required);
    }

    public function testFullSizeDefaultsFalse(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $this->assertFalse($result->fullSize);
    }

    public function testDocStatesIsNullByDefault(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $this->assertNull($result->docStates);
    }

    public function testGeneralTabComesFirst(): void
    {
        $def = $this->makeTableDef(
            columns: [
                ['id' => 'grouped', 'name' => 'Grouped', 'type' => 'varchar', 'length' => 50, 'group' => 'info'],
                ['id' => 'ungrouped', 'name' => 'Ungrouped', 'type' => 'varchar', 'length' => 50],
            ],
            columnGroups: [
                ['id' => 'info', 'name' => 'Info'],
            ],
        );

        $builder = new AutoFormBuilder();
        $result = $builder->build($def);

        $this->assertCount(2, $result->tabs);
        $this->assertSame('general', $result->tabs[0]->id);
        $this->assertSame('info', $result->tabs[1]->id);
    }
}
