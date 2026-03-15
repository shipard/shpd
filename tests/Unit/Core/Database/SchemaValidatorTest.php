<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\SchemaValidator;

class SchemaValidatorTest extends TestCase
{
    private function validTable(array $overrides = []): array
    {
        return array_merge([
            'tableId' => 1,
            'name' => 'Orders',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'amount', 'name' => 'Amount', 'type' => 'numeric', 'precision' => 10, 'scale' => 2],
            ],
            'indexes' => [],
            'columnGroups' => [],
        ], $overrides);
    }

    public function testValidSingleTable(): void
    {
        $result = SchemaValidator::validate([$this->validTable()]);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['warnings']);
    }

    public function testDuplicateTableId(): void
    {
        $result = SchemaValidator::validate([
            $this->validTable(['tableId' => 5, 'name' => 'TableA']),
            $this->validTable(['tableId' => 5, 'name' => 'TableB']),
        ]);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Duplicate tableId 5', $result['errors'][0]);
        $this->assertStringContainsString('TableA', $result['errors'][0]);
        $this->assertStringContainsString('TableB', $result['errors'][0]);
    }

    public function testMissingTableId(): void
    {
        $table = $this->validTable();
        unset($table['tableId']);
        $result = SchemaValidator::validate([$table]);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString("missing required field: tableId", $result['errors'][0]);
    }

    public function testMissingPrimaryKey(): void
    {
        $table = $this->validTable([
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int'],
            ],
        ]);
        $result = SchemaValidator::validate([$table]);
        $errors = array_filter($result['errors'], fn(string $e) => str_contains($e, 'no primary key'));
        $this->assertNotEmpty($errors);
    }

    public function testDuplicateColumnId(): void
    {
        $table = $this->validTable([
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'id', 'name' => 'ID duplicate', 'type' => 'varchar', 'length' => 50],
            ],
        ]);
        $result = SchemaValidator::validate([$table]);
        $errors = array_filter($result['errors'], fn(string $e) => str_contains($e, "Duplicate column id 'id'"));
        $this->assertNotEmpty($errors);
    }

    public function testDuplicateIndexId(): void
    {
        $table = $this->validTable([
            'indexes' => [
                ['id' => 'idx_foo', 'type' => 'index', 'columns' => [['column' => 'id']]],
                ['id' => 'idx_foo', 'type' => 'index', 'columns' => [['column' => 'amount']]],
            ],
        ]);
        $result = SchemaValidator::validate([$table]);
        $errors = array_filter($result['errors'], fn(string $e) => str_contains($e, "Duplicate index id 'idx_foo'"));
        $this->assertNotEmpty($errors);
    }

    public function testUnusedColumnGroup(): void
    {
        $table = $this->validTable([
            'columnGroups' => [
                ['id' => 'meta', 'name' => 'Meta'],
            ],
        ]);
        $result = SchemaValidator::validate([$table]);
        $this->assertEmpty($result['errors']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString("Column group 'meta'", $result['warnings'][0]);
        $this->assertStringContainsString('no columns assigned', $result['warnings'][0]);
    }

    public function testUsedColumnGroupNoWarning(): void
    {
        $table = $this->validTable([
            'columnGroups' => [
                ['id' => 'meta', 'name' => 'Meta'],
            ],
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'note', 'name' => 'Note', 'type' => 'text', 'nullable' => true, 'group' => 'meta'],
            ],
        ]);
        $result = SchemaValidator::validate([$table]);
        $this->assertEmpty($result['warnings']);
    }

    public function testMultipleErrorsReported(): void
    {
        $tables = [
            // missing tableId
            array_merge($this->validTable(['name' => 'Alpha']), ['tableId' => null]),
            // duplicate column
            $this->validTable([
                'tableId' => 2,
                'name' => 'Beta',
                'columns' => [
                    ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                    ['id' => 'id', 'name' => 'ID2', 'type' => 'text'],
                ],
            ]),
        ];
        // unset tableId to simulate missing field
        unset($tables[0]['tableId']);

        $result = SchemaValidator::validate($tables);
        $this->assertGreaterThanOrEqual(2, count($result['errors']));
    }
}
