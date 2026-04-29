<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Items\ItemDocument;

class ItemDocumentTest extends TestCase
{
    private function doc(): ItemDocument
    {
        return new ItemDocument();
    }

    public function testValidateMissingNameFails(): void
    {
        $data = ['item_kind' => 1, 'unit' => 1];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('name', $columns);
    }

    public function testValidateMissingItemKindFails(): void
    {
        $data = ['name' => 'Konzultace', 'unit' => 1];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('item_kind', $columns);
    }

    public function testValidateMissingUnitFails(): void
    {
        $data = ['name' => 'Konzultace', 'item_kind' => 1];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('unit', $columns);
    }

    public function testValidateNegativePriceFails(): void
    {
        $data = [
            'name' => 'Konzultace',
            'item_kind' => 1,
            'unit' => 1,
            'sales_price_no_vat' => -10,
        ];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('sales_price_no_vat', $columns);
    }

    public function testValidateAllOkWithoutDb(): void
    {
        // Without a DB the validator skips reference / uniqueness checks but
        // still confirms required fields are present.
        $data = [
            'name' => 'Konzultace',
            'item_kind' => 1,
            'unit' => 1,
            'sales_price_no_vat' => 0,
        ];
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testBeforeSaveAutoGeneratesSixHexCodeWhenEmpty(): void
    {
        // Mock returns NULL for both code uniqueness lookup and item_kind lookup
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['name' => 'X', 'item_kind' => 1, 'unit' => 1];
        $doc->beforeSave($data);

        $this->assertArrayHasKey('code', $data);
        $this->assertSame(6, strlen($data['code']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $data['code']);
    }

    public function testBeforeSaveDenormalisesItemTypeFromKind(): void
    {
        $kindRow = new \Dibi\Row(['item_type' => 2]);

        // First fetch is for code uniqueness (returns null = unique);
        // second fetch resolves item_type from item_kind.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnOnConsecutiveCalls(null, $kindRow);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'name' => 'X',
            'item_kind' => 7,
            'unit' => 1,
            'item_type' => 0, // value sent by the form is overridden
        ];
        $doc->beforeSave($data);

        $this->assertSame(2, $data['item_type']);
    }

    public function testBeforeSaveKeepsUserSuppliedCode(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['name' => 'X', 'code' => 'ITEM-42', 'item_kind' => 1, 'unit' => 1];
        $doc->beforeSave($data);

        $this->assertSame('ITEM-42', $data['code']);
    }
}
