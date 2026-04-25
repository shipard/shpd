<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\SchemaIntrospector;
use Shipard\Core\Database\TableDefinition;

class SchemaIntrospectorTest extends TestCase
{
    private function makeTable(int $tableId, string $name, array $columns): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => $tableId,
            'name' => $name,
            'columns' => array_merge(
                [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
                $columns,
            ),
        ]);
    }

    public function testReturnsEmptyForTablesWithoutEncryptedColumns(): void
    {
        $tables = [
            'a' => $this->makeTable(1, 'a', [
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ]),
            'b' => $this->makeTable(2, 'b', [
                ['id' => 'note', 'name' => 'Note', 'type' => 'text', 'nullable' => true],
            ]),
        ];

        $this->assertSame([], SchemaIntrospector::findEncryptedColumns($tables));
    }

    public function testFindsSingleEncryptedColumn(): void
    {
        $tables = [
            'core_mail_ai_backends' => $this->makeTable(101, 'AI Backends', [
                ['id' => 'provider', 'name' => 'Provider', 'type' => 'varchar', 'length' => 50],
                ['id' => 'api_key', 'name' => 'API key', 'type' => 'encrypted_text', 'nullable' => true],
            ]),
        ];

        $this->assertSame(
            [['table' => 'core_mail_ai_backends', 'column' => 'api_key']],
            SchemaIntrospector::findEncryptedColumns($tables),
        );
    }

    public function testFindsMultipleEncryptedColumnsAcrossTables(): void
    {
        $tables = [
            'a' => $this->makeTable(1, 'a', [
                ['id' => 'token', 'name' => 'Token', 'type' => 'encrypted_text', 'nullable' => true],
                ['id' => 'note', 'name' => 'Note', 'type' => 'text', 'nullable' => true],
            ]),
            'b' => $this->makeTable(2, 'b', [
                ['id' => 'secret', 'name' => 'Secret', 'type' => 'encrypted_text', 'nullable' => true],
                ['id' => 'refresh', 'name' => 'Refresh', 'type' => 'encrypted_text', 'nullable' => true],
            ]),
        ];

        $found = SchemaIntrospector::findEncryptedColumns($tables);
        $this->assertSame(
            [
                ['table' => 'a', 'column' => 'token'],
                ['table' => 'b', 'column' => 'secret'],
                ['table' => 'b', 'column' => 'refresh'],
            ],
            $found,
        );
    }

    public function testAcceptsGenerator(): void
    {
        $tables = [
            'a' => $this->makeTable(1, 'a', [
                ['id' => 'x', 'name' => 'X', 'type' => 'encrypted_text', 'nullable' => true],
            ]),
        ];
        $gen = (function () use ($tables) {
            foreach ($tables as $k => $v) {
                yield $k => $v;
            }
        })();

        $found = SchemaIntrospector::findEncryptedColumns($gen);
        $this->assertSame([['table' => 'a', 'column' => 'x']], $found);
    }
}
