<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\IndexDefinition;
use Shipard\Core\Database\TableDefinition;

class TableDefinitionTest extends TestCase
{
    private function coreSystemUsersData(): array
    {
        return [
            'tableId' => 1,
            'name' => 'Users',
            'name:cs' => 'Uživatelé',
            'name:en' => 'Users',
            'columnGroups' => [
                [
                    'id' => 'credentials',
                    'name' => 'Credentials',
                    'name:cs' => 'Přihlašovací údaje',
                    'name:en' => 'Credentials',
                ],
                [
                    'id' => 'personal',
                    'name' => 'Personal information',
                    'name:cs' => 'Osobní údaje',
                    'name:en' => 'Personal information',
                ],
            ],
            'columns' => [
                [
                    'id' => 'id',
                    'name' => 'ID',
                    'type' => 'int',
                    'autoIncrement' => true,
                    'primaryKey' => true,
                ],
                [
                    'id' => 'login',
                    'name' => 'Login',
                    'name:cs' => 'Přihlašovací jméno',
                    'name:en' => 'Login',
                    'type' => 'varchar',
                    'length' => 100,
                    'nullable' => false,
                    'group' => 'credentials',
                ],
                [
                    'id' => 'password_hash',
                    'name' => 'Password hash',
                    'name:cs' => 'Hash hesla',
                    'name:en' => 'Password hash',
                    'type' => 'varchar',
                    'length' => 255,
                    'nullable' => false,
                    'group' => 'credentials',
                ],
                [
                    'id' => 'full_name',
                    'name' => 'Full name',
                    'name:cs' => 'Celé jméno',
                    'name:en' => 'Full name',
                    'type' => 'varchar',
                    'length' => 200,
                    'nullable' => false,
                    'group' => 'personal',
                ],
                [
                    'id' => 'email',
                    'name' => 'E-mail',
                    'type' => 'varchar',
                    'length' => 200,
                    'nullable' => true,
                    'group' => 'personal',
                ],
                [
                    'id' => 'is_active',
                    'name' => 'Active',
                    'name:cs' => 'Aktivní',
                    'name:en' => 'Active',
                    'type' => 'boolean',
                    'default' => 1,
                ],
            ],
            'indexes' => [
                [
                    'id' => 'unq_login',
                    'type' => 'unique',
                    'columns' => [
                        ['column' => 'login'],
                    ],
                ],
                [
                    'id' => 'idx_email',
                    'type' => 'index',
                    'columns' => [
                        ['column' => 'email'],
                    ],
                ],
            ],
        ];
    }

    public function testCoreSystemUsersTable(): void
    {
        $table = TableDefinition::fromArray($this->coreSystemUsersData());

        $this->assertSame(1, $table->tableId);
        $this->assertSame('Users', $table->name);
        $this->assertCount(6, $table->columns);
        $this->assertCount(2, $table->indexes);
        $this->assertCount(2, $table->columnGroups);

        $primaryCols = array_filter($table->columns, fn(ColumnDefinition $c) => $c->primaryKey);
        $this->assertCount(1, $primaryCols);

        $pk = array_values($primaryCols)[0];
        $this->assertSame('id', $pk->id);
        $this->assertTrue($pk->autoIncrement);
    }

    public function testMissingTableId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray([]);
    }

    public function testMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray(['tableId' => 1]);
    }

    public function testMissingPrimaryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int'],
            ],
        ]);
    }

    public function testMultiplePrimaryKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'id2', 'name' => 'ID2', 'type' => 'int', 'primaryKey' => true],
            ],
        ]);
    }

    public function testVarcharWithoutLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'varchar']);
    }

    public function testNumericWithoutPrecision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'numeric']);
    }

    public function testNumericWithoutScale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'numeric', 'precision' => 12]);
    }

    public function testEnumIntWithoutCfgItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'enumInt']);
    }

    public function testEnumStringWithoutLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'enumString', 'cfgItem' => 'some.item']);
    }

    public function testIndexMultiColumnWithOrder(): void
    {
        $index = IndexDefinition::fromArray([
            'id' => 'idx_test',
            'type' => 'index',
            'columns' => [
                ['column' => 'issue_date', 'order' => 'ASC'],
                ['column' => 'doc_state', 'order' => 'DESC'],
            ],
        ]);

        $this->assertCount(2, $index->columns);
        $this->assertSame('issue_date', $index->columns[0]['column']);
        $this->assertSame('ASC', $index->columns[0]['order']);
        $this->assertSame('doc_state', $index->columns[1]['column']);
        $this->assertSame('DESC', $index->columns[1]['order']);
    }

    public function testInvalidIndexType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndexDefinition::fromArray([
            'id' => 'idx_test',
            'type' => 'badtype',
            'columns' => [['column' => 'foo']],
        ]);
    }

    public function testEmptyIndexColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndexDefinition::fromArray([
            'id' => 'idx_test',
            'type' => 'index',
            'columns' => [],
        ]);
    }
}
