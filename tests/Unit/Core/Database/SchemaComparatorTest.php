<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\SchemaComparator;
use Shipard\Core\Database\TableDefinition;

class SchemaComparatorTest extends TestCase
{
    private function makeTable(array $extra = []): TableDefinition
    {
        return TableDefinition::fromArray(array_merge([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ],
        ], $extra));
    }

    public function testEmptyExistingColumnsReturnsCreateTable(): void
    {
        $def = $this->makeTable();
        $ops = SchemaComparator::compare($def, [], []);

        $this->assertCount(1, $ops);
        $this->assertSame('create_table', $ops[0]['op']);
        $this->assertSame($def, $ops[0]['definition']);
    }

    public function testMissingColumnReturnsAddColumn(): void
    {
        $def = $this->makeTable();
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)'], []);

        $this->assertCount(1, $ops);
        $this->assertSame('add_column', $ops[0]['op']);
        $this->assertSame('name', $ops[0]['column']->id);
    }

    public function testExistingColumnNoChange(): void
    {
        $def = $this->makeTable();
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'name' => 'varchar(100)'], []);
        $this->assertCount(0, $ops);
    }

    public function testRelaxingNotNullToNullableIsSafeModify(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100, 'nullable' => true],
            ],
        ]);
        $ops = SchemaComparator::compare(
            $def,
            ['id' => 'int(11)', 'name' => 'varchar(100)'],
            [],
            ['id' => false, 'name' => false],
        );

        $this->assertCount(1, $ops);
        $this->assertSame('modify_column', $ops[0]['op']);
        $this->assertSame('name', $ops[0]['column']->id);
    }

    public function testTighteningNullableToNotNullIsNeverModified(): void
    {
        // Opačný směr (NULL → NOT NULL) není bezpečný — existující NULL
        // hodnoty by MODIFY rozbily.
        $def = $this->makeTable();
        $ops = SchemaComparator::compare(
            $def,
            ['id' => 'int(11)', 'name' => 'varchar(100)'],
            [],
            ['id' => false, 'name' => true],
        );

        $this->assertCount(0, $ops);
    }

    public function testNullabilityIgnoredWhenNotProvided(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100, 'nullable' => true],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'name' => 'varchar(100)'], []);
        $this->assertCount(0, $ops);
    }

    public function testSafeVarcharExtension(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'title', 'name' => 'Title', 'type' => 'varchar', 'length' => 100],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'title' => 'varchar(50)'], []);

        $this->assertCount(1, $ops);
        $this->assertSame('modify_column', $ops[0]['op']);
        $this->assertSame('title', $ops[0]['column']->id);
    }

    public function testUnsafeVarcharShortening(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'title', 'name' => 'Title', 'type' => 'varchar', 'length' => 50],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'title' => 'varchar(100)'], []);
        $this->assertCount(0, $ops);
    }

    public function testSafeIntExpansionToSmallint(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'val', 'name' => 'Val', 'type' => 'smallint'],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'val' => 'tinyint'], []);

        $this->assertCount(1, $ops);
        $this->assertSame('modify_column', $ops[0]['op']);
        $this->assertSame('val', $ops[0]['column']->id);
    }

    public function testSafeIntExpansionToInt(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'val', 'name' => 'Val', 'type' => 'int'],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'val' => 'smallint'], []);

        $this->assertCount(1, $ops);
        $this->assertSame('modify_column', $ops[0]['op']);
    }

    public function testSafeIntExpansionToBigint(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'val', 'name' => 'Val', 'type' => 'bigint'],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'val' => 'int'], []);

        $this->assertCount(1, $ops);
        $this->assertSame('modify_column', $ops[0]['op']);
    }

    public function testUnsafeTypeChange(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'code', 'name' => 'Code', 'type' => 'int'],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'code' => 'varchar(50)'], []);
        $this->assertCount(0, $ops);
    }

    public function testSafeNumericPrecisionExpansion(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'amount', 'name' => 'Amount', 'type' => 'numeric', 'precision' => 12, 'scale' => 2],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'amount' => 'numeric(10,2)'], []);

        $this->assertCount(1, $ops);
        $this->assertSame('modify_column', $ops[0]['op']);
        $this->assertSame('amount', $ops[0]['column']->id);
    }

    public function testUnsafeNumericScaleChange(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'amount', 'name' => 'Amount', 'type' => 'numeric', 'precision' => 10, 'scale' => 4],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)', 'amount' => 'numeric(10,2)'], []);
        $this->assertCount(0, $ops);
    }

    public function testMissingIndexReturnsCreateIndex(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
            ],
            'indexes' => [
                ['id' => 'idx_foo', 'type' => 'index', 'columns' => [['column' => 'id']]],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)'], []);

        $this->assertCount(1, $ops);
        $this->assertSame('create_index', $ops[0]['op']);
        $this->assertSame('idx_foo', $ops[0]['index']->id);
    }

    public function testExistingIndexIgnored(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
            ],
            'indexes' => [
                ['id' => 'idx_foo', 'type' => 'index', 'columns' => [['column' => 'id']]],
            ],
        ]);
        $ops = SchemaComparator::compare($def, ['id' => 'int(11)'], ['idx_foo']);
        $this->assertCount(0, $ops);
    }

    public function testExcessiveDbColumnsIgnored(): void
    {
        $def = $this->makeTable();
        $ops = SchemaComparator::compare($def, [
            'id' => 'int(11)',
            'name' => 'varchar(100)',
            'extra_col' => 'text',
        ], []);
        $this->assertCount(0, $ops);
    }
}
