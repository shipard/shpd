<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Items\ItemKindDocument;

class ItemKindDocumentTest extends TestCase
{
    private function doc(): ItemKindDocument
    {
        return new ItemKindDocument();
    }

    public function testValidateMissingNameFails(): void
    {
        $data = ['item_type' => 0];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('name', $columns);
    }

    public function testValidateMissingItemTypeFails(): void
    {
        $data = ['name' => 'Konzultace'];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('item_type', $columns);
    }

    public function testValidateUnknownItemTypeFails(): void
    {
        $data = ['name' => 'Konzultace', 'item_type' => 99];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'item_type',
        );
        $this->assertNotEmpty($errors);
        $this->assertSame('invalid', array_values($errors)[0]['code']);
    }

    public function testValidateNewKindWithoutDbPasses(): void
    {
        $data = ['name' => 'Služba IT', 'item_type' => 0];
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateChangeOfItemTypeOnUsedKindIsRejected(): void
    {
        $existing = new \Dibi\Row(['item_type' => 0]);
        $usage = new \Dibi\Row(['n' => 5]);

        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnOnConsecutiveCalls($existing, $usage);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['id' => 7, 'name' => 'Služba IT', 'item_type' => 1];
        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'item_type',
        );
        $this->assertNotEmpty($errors);
        $this->assertSame('in_use', array_values($errors)[0]['code']);
    }

    public function testValidateChangeOfItemTypeOnUnusedKindPasses(): void
    {
        $existing = new \Dibi\Row(['item_type' => 0]);
        $usage = new \Dibi\Row(['n' => 0]);

        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnOnConsecutiveCalls($existing, $usage);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['id' => 7, 'name' => 'Služba IT', 'item_type' => 1];
        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }
}
