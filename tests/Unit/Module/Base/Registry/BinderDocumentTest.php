<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Registry\BinderDocument;

/**
 * Validace a beforeSave logika BinderDocument. Unikátnost názvu mezi
 * živými šanony (docState != 90) používá mock přes Dibi\Connection.
 */
class BinderDocumentTest extends TestCase
{
    // --- validate -----------------------------------------------------------

    public function testValidateMissingNameFails(): void
    {
        $doc = new BinderDocument();
        $data = ['notice' => 'bez názvu'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('name', $columns);
    }

    public function testValidateBlankNameFails(): void
    {
        $doc = new BinderDocument();
        $data = ['name' => '   '];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
    }

    public function testValidateWithoutDbSkipsUniquenessCheck(): void
    {
        $doc = new BinderDocument();
        $data = ['name' => 'Pojištění'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateDuplicateNameAmongLiveFails(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (string $sql, ...$params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return new \Dibi\Row(['id' => 7]);
            },
        );

        $doc = new BinderDocument();
        $doc->setDb($db);
        $data = ['name' => 'Pojištění'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'name',
        );
        $first = array_values($errors)[0];
        $this->assertSame('duplicate_name', $first['code']);

        // Koš neblokuje reuse názvu — dotaz vylučuje docState 90.
        $this->assertStringContainsString('docState != %i', $capturedSql);
        $this->assertContains(90, $capturedParams);
    }

    public function testValidateUniqueNamePasses(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = new BinderDocument();
        $doc->setDb($db);
        $data = ['name' => 'Auta'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateUpdateExcludesSelf(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (string $sql, ...$params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return null;
            },
        );

        $doc = new BinderDocument();
        $doc->setDb($db);
        $data = ['id' => 5, 'name' => 'Auta'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertStringContainsString('id != %i', $capturedSql);
        $this->assertContains(5, $capturedParams);
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveFillsCreatedForNewRecord(): void
    {
        $doc = new BinderDocument();
        $data = ['name' => 'Auta'];

        $doc->beforeSave($data);

        $this->assertArrayHasKey('created', $data);
    }

    public function testBeforeSaveKeepsCreatedOnUpdate(): void
    {
        $doc = new BinderDocument();
        $created = '2026-01-01 00:00:00';
        $data = ['id' => 5, 'name' => 'Auta', 'created' => $created];

        $doc->beforeSave($data);

        $this->assertSame($created, $data['created']);
    }

    public function testBeforeSaveTrimsName(): void
    {
        $doc = new BinderDocument();
        $data = ['name' => '  Auta  '];

        $doc->beforeSave($data);

        $this->assertSame('Auta', $data['name']);
    }
}
