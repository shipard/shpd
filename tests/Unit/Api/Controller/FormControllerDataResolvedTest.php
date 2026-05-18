<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\FormController;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormColumn;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormSection;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Form\Lookup\TableLookup;

class FormControllerDataResolvedTest extends TestCase
{
    private FormController $ctrl;
    /** @var \ReflectionMethod */
    private \ReflectionMethod $collectMethod;
    /** @var \ReflectionMethod */
    private \ReflectionMethod $buildMethod;

    protected function setUp(): void
    {
        $this->ctrl = new FormController();
        $ref = new \ReflectionClass(FormController::class);
        $this->collectMethod = $ref->getMethod('collectLookupElements');
        $this->buildMethod = $ref->getMethod('buildDataResolved');
    }

    private function lookupElement(string $column, string $table): FormElement
    {
        return new FormElement(
            type: 'lookup',
            column: $column,
            lookup: ['table' => $table, 'filter' => null],
        );
    }

    private function formDefWith(array $elements): FormDefinition
    {
        return new FormDefinition(
            table: 't',
            title: 'T',
            titleNew: 'New',
            tabs: [
                new FormTab(
                    id: 'h',
                    label: 'H',
                    sections: [new FormSection(columns: [new FormColumn(elements: $elements)])],
                    type: 'fields',
                ),
            ],
        );
    }

    private function makeTable(string $name): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name'    => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);
    }

    public function testCollectFindsLookupElements(): void
    {
        $formDef = $this->formDefWith([
            new FormElement(type: 'input', column: 'name'),
            $this->lookupElement('partner', 'base_persons_persons'),
            new FormElement(type: 'input', column: 'note'),
            $this->lookupElement('partner_address', 'base_persons_addresses'),
        ]);

        $result = $this->collectMethod->invoke($this->ctrl, $formDef);

        $this->assertCount(2, $result);
        $this->assertSame('partner', $result[0]->column);
        $this->assertSame('partner_address', $result[1]->column);
    }

    public function testCollectSkipsNonFieldsTabs(): void
    {
        $formDef = new FormDefinition(
            table: 't', title: 'T', titleNew: 'New',
            tabs: [
                new FormTab(
                    id: 'sub', label: 'Subtable',
                    type: 'subtable',
                    subtable: ['table' => 'x', 'foreignKey' => 'parent', 'formId' => null],
                ),
                new FormTab(
                    id: 'h', label: 'H',
                    sections: [new FormSection(columns: [new FormColumn(elements: [
                        $this->lookupElement('partner', 'base_persons_persons'),
                    ])])],
                    type: 'fields',
                ),
            ],
        );

        $result = $this->collectMethod->invoke($this->ctrl, $formDef);

        $this->assertCount(1, $result);
    }

    public function testBuildDataResolvedHappyPath(): void
    {
        $formDef = $this->formDefWith([
            $this->lookupElement('partner', 'base_persons_persons'),
        ]);
        $registry = new LookupRegistry();
        $registry->register('base_persons_persons', DataResolvedFakeLookup::class);
        DataResolvedFakeLookup::$nextItems = [new LookupItem(id: 42, primary: 'Testování 999', secondary: 'IČO 12345678')];

        $tables = ['base_persons_persons' => $this->makeTable('Persons')];
        $db = $this->createMock(DataSourceConnection::class);

        $result = $this->buildMethod->invoke(
            $this->ctrl, $formDef, ['partner' => 42], $registry, $db, null, $tables,
        );

        $this->assertArrayHasKey('partner', $result);
        $this->assertSame(['id' => 42, 'primary' => 'Testování 999', 'secondary' => 'IČO 12345678'], $result['partner']);
        $this->assertSame([42], DataResolvedFakeLookup::$lastIds);
    }

    public function testBuildSkipsNullValues(): void
    {
        $formDef = $this->formDefWith([
            $this->lookupElement('partner', 'base_persons_persons'),
        ]);
        $registry = new LookupRegistry();
        $registry->register('base_persons_persons', DataResolvedFakeLookup::class);
        $tables = ['base_persons_persons' => $this->makeTable('Persons')];
        $db = $this->createMock(DataSourceConnection::class);

        $result = $this->buildMethod->invoke(
            $this->ctrl, $formDef, ['partner' => null], $registry, $db, null, $tables,
        );

        $this->assertSame([], $result);
    }

    public function testBuildSkipsEmptyStringValues(): void
    {
        $formDef = $this->formDefWith([
            $this->lookupElement('partner', 'base_persons_persons'),
        ]);
        $registry = new LookupRegistry();
        $registry->register('base_persons_persons', DataResolvedFakeLookup::class);
        $tables = ['base_persons_persons' => $this->makeTable('Persons')];
        $db = $this->createMock(DataSourceConnection::class);

        $result = $this->buildMethod->invoke(
            $this->ctrl, $formDef, ['partner' => ''], $registry, $db, null, $tables,
        );

        $this->assertSame([], $result);
    }

    public function testBuildSkipsUnresolvedRecords(): void
    {
        $formDef = $this->formDefWith([
            $this->lookupElement('partner', 'base_persons_persons'),
        ]);
        $registry = new LookupRegistry();
        $registry->register('base_persons_persons', DataResolvedFakeLookup::class);
        DataResolvedFakeLookup::$nextItems = []; // resolve fails

        $tables = ['base_persons_persons' => $this->makeTable('Persons')];
        $db = $this->createMock(DataSourceConnection::class);

        $result = $this->buildMethod->invoke(
            $this->ctrl, $formDef, ['partner' => 99999], $registry, $db, null, $tables,
        );

        $this->assertSame([], $result);
    }

    public function testBuildSkipsUnregisteredLookupTable(): void
    {
        $formDef = $this->formDefWith([
            $this->lookupElement('partner', 'unknown_table'),
        ]);
        $registry = new LookupRegistry();
        $tables = ['unknown_table' => $this->makeTable('Unknown')];
        $db = $this->createMock(DataSourceConnection::class);

        $result = $this->buildMethod->invoke(
            $this->ctrl, $formDef, ['partner' => 1], $registry, $db, null, $tables,
        );

        $this->assertSame([], $result);
    }
}

class DataResolvedFakeLookup extends TableLookup
{
    /** @var list<LookupItem> */
    public static array $nextItems = [];

    /** @var list<int|string> */
    public static array $lastIds = [];

    public function search(string $q, array $filter, int $limit): array
    {
        return [];
    }

    public function resolve(array $ids): array
    {
        self::$lastIds = $ids;
        return self::$nextItems;
    }
}
