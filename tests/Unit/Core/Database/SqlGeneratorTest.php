<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\IndexDefinition;
use Shipard\Core\Database\SqlGenerator;
use Shipard\Core\Database\TableDefinition;

class SqlGeneratorTest extends TestCase
{
    private function makeCol(array $data): ColumnDefinition
    {
        return ColumnDefinition::fromArray(array_merge(['name' => 'Test'], $data));
    }

    private function makeIndex(array $data): IndexDefinition
    {
        return IndexDefinition::fromArray($data);
    }

    private function simpleTableDef(): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'label', 'name' => 'Label', 'type' => 'varchar', 'length' => 50],
            ],
        ]);
    }

    // Column definition tests

    public function testVarcharNotNull(): void
    {
        $col = $this->makeCol(['id' => 'title', 'type' => 'varchar', 'length' => 50]);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('VARCHAR(50)', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
    }

    public function testVarcharNullable(): void
    {
        $col = $this->makeCol(['id' => 'title', 'type' => 'varchar', 'length' => 50, 'nullable' => true]);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('VARCHAR(50)', $sql);
        $this->assertStringContainsString(' NULL', $sql);
        $this->assertStringNotContainsString('NOT NULL', $sql);
    }

    public function testEnumStringCharset(): void
    {
        $col = $this->makeCol(['id' => 'status', 'type' => 'enumString', 'length' => 10, 'cfgItem' => 'some.item']);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('CHAR(10) CHARACTER SET ascii', $sql);
    }

    public function testEnumInt(): void
    {
        $col = $this->makeCol(['id' => 'kind', 'type' => 'enumInt', 'cfgItem' => 'some.item']);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('SMALLINT', $sql);
    }

    public function testNumeric(): void
    {
        $col = $this->makeCol(['id' => 'amount', 'type' => 'numeric', 'precision' => 12, 'scale' => 2]);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('NUMERIC(12, 2)', $sql);
    }

    public function testBoolean(): void
    {
        $col = $this->makeCol(['id' => 'active', 'type' => 'boolean']);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('TINYINT(1)', $sql);
    }

    public function testAllIntTypes(): void
    {
        foreach (['tinyint' => 'TINYINT', 'smallint' => 'SMALLINT', 'int' => 'INT', 'bigint' => 'BIGINT'] as $phpType => $sqlType) {
            $col = $this->makeCol(['id' => 'x', 'type' => $phpType]);
            $sql = SqlGenerator::generateAddColumn('t', $col);
            $this->assertStringContainsString($sqlType, $sql, "Expected {$sqlType} for type {$phpType}");
        }
    }

    public function testTextTypes(): void
    {
        foreach (['text' => 'TEXT', 'longtext' => 'LONGTEXT', 'json' => 'JSON', 'date' => 'DATE', 'datetime' => 'DATETIME', 'time' => 'TIME', 'encrypted_text' => 'TEXT'] as $phpType => $sqlType) {
            $col = $this->makeCol(['id' => 'x', 'type' => $phpType]);
            $sql = SqlGenerator::generateAddColumn('t', $col);
            $this->assertStringContainsString($sqlType, $sql, "Expected {$sqlType} for type {$phpType}");
        }
    }

    public function testEncryptedTextNullable(): void
    {
        $col = $this->makeCol(['id' => 'api_key', 'type' => 'encrypted_text', 'nullable' => true]);
        $sql = SqlGenerator::generateAddColumn('core_mail_ai_backends', $col);
        $this->assertStringContainsString('`api_key` TEXT NULL', $sql);
    }

    public function testAutoIncrement(): void
    {
        $col = $this->makeCol(['id' => 'id', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    public function testDefaultValueNumeric(): void
    {
        $col = $this->makeCol(['id' => 'qty', 'type' => 'int', 'default' => 0]);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString('DEFAULT 0', $sql);
        $this->assertStringNotContainsString("DEFAULT '", $sql);
    }

    public function testDefaultValueString(): void
    {
        $col = $this->makeCol(['id' => 'code', 'type' => 'varchar', 'length' => 10, 'default' => 'draft']);
        $sql = SqlGenerator::generateAddColumn('t', $col);
        $this->assertStringContainsString("DEFAULT 'draft'", $sql);
    }

    // CREATE TABLE tests

    public function testCreateTable(): void
    {
        $def = $this->simpleTableDef();
        $sql = SqlGenerator::generateCreateTable('my_table', $def);

        $this->assertStringContainsString('CREATE TABLE `my_table`', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
        $this->assertStringContainsString('CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci', $sql);
        $this->assertStringContainsString('`id`', $sql);
        $this->assertStringContainsString('`label`', $sql);
    }

    public function testCreateTableWithIndex(): void
    {
        $def = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 20],
            ],
            'indexes' => [
                ['id' => 'idx_code', 'type' => 'index', 'columns' => [['column' => 'code']]],
            ],
        ]);

        $createSql = SqlGenerator::generateCreateTable('my_table', $def);
        $indexSql = SqlGenerator::generateCreateIndex('my_table', $def->indexes[0]);

        // Index must NOT appear inside CREATE TABLE
        $this->assertStringNotContainsString('idx_code', $createSql);
        $this->assertStringContainsString('idx_code', $indexSql);
    }

    public function testCreateUniqueIndex(): void
    {
        $index = $this->makeIndex(['id' => 'unq_email', 'type' => 'unique', 'columns' => [['column' => 'email']]]);
        $sql = SqlGenerator::generateCreateIndex('users', $index);
        $this->assertStringContainsString('CREATE UNIQUE INDEX `unq_email`', $sql);
        $this->assertStringContainsString('ON `users`', $sql);
    }

    public function testCreateFulltextIndex(): void
    {
        $index = $this->makeIndex(['id' => 'ft_body', 'type' => 'fulltext', 'columns' => [['column' => 'body']]]);
        $sql = SqlGenerator::generateCreateIndex('articles', $index);
        $this->assertStringContainsString('CREATE FULLTEXT INDEX `ft_body`', $sql);
    }

    public function testCreatePlainIndex(): void
    {
        $index = $this->makeIndex(['id' => 'idx_date', 'type' => 'index', 'columns' => [['column' => 'created_at']]]);
        $sql = SqlGenerator::generateCreateIndex('docs', $index);
        $this->assertStringStartsWith('CREATE INDEX', $sql);
    }

    public function testAddColumn(): void
    {
        $col = $this->makeCol(['id' => 'note', 'type' => 'text', 'nullable' => true]);
        $sql = SqlGenerator::generateAddColumn('orders', $col);
        $this->assertStringStartsWith('ALTER TABLE `orders` ADD COLUMN', $sql);
        $this->assertStringContainsString('`note`', $sql);
    }

    public function testModifyColumn(): void
    {
        $col = $this->makeCol(['id' => 'title', 'type' => 'varchar', 'length' => 200]);
        $sql = SqlGenerator::generateModifyColumn('products', $col);
        $this->assertStringStartsWith('ALTER TABLE `products` MODIFY COLUMN', $sql);
        $this->assertStringContainsString('`title`', $sql);
        $this->assertStringContainsString('VARCHAR(200)', $sql);
    }

    public function testMultiColumnIndexOrder(): void
    {
        $index = $this->makeIndex([
            'id' => 'idx_multi',
            'type' => 'index',
            'columns' => [
                ['column' => 'date', 'order' => 'ASC'],
                ['column' => 'state', 'order' => 'DESC'],
            ],
        ]);
        $sql = SqlGenerator::generateCreateIndex('docs', $index);
        $this->assertStringContainsString('`date` ASC', $sql);
        $this->assertStringContainsString('`state` DESC', $sql);
    }
}
