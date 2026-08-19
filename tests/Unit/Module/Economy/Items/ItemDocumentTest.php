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

    /**
     * DB mock pro validaci účtu: druh/jednotka existují, dotaz na
     * economy_accounting_accounts vrací $accountRow.
     */
    private function dbForAccountValidation(?\Dibi\Row $accountRow): \Dibi\Connection
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (...$args) use ($accountRow): ?\Dibi\Row {
                $sql = (string) ($args[0] ?? '');
                if (str_contains($sql, 'economy_accounting_accounts')) {
                    return $accountRow;
                }
                if (str_contains($sql, 'economy_items WHERE code')) {
                    return null; // kód unikátní
                }
                return new \Dibi\Row(['id' => 1]);
            },
        );
        return $db;
    }

    public function testValidateAccountingAccountMustBeActiveAnalytic(): void
    {
        // Dotaz s podmínkou account_level = 4 + aktivní stav nic nenajde
        $doc = $this->doc();
        $doc->setDb($this->dbForAccountValidation(null));

        $data = ['name' => 'Bankovní poplatek', 'item_kind' => 1, 'unit' => 1,
                 'accounting_account' => 99];
        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('accounting_account', $columns);
    }

    public function testValidateValidAccountingAccountPasses(): void
    {
        $doc = $this->doc();
        $doc->setDb($this->dbForAccountValidation(new \Dibi\Row(['id' => 99])));

        $data = ['name' => 'Bankovní poplatek', 'item_kind' => 1, 'unit' => 1,
                 'accounting_account' => 99];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testValidateEmptyAccountingAccountIsFine(): void
    {
        $doc = $this->doc();
        $doc->setDb($this->dbForAccountValidation(null));

        $data = ['name' => 'Konzultace', 'item_kind' => 1, 'unit' => 1,
                 'accounting_account' => null];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    // -------- content_tags --------

    private function docWithTaxonomy(): ItemDocument
    {
        $config = $this->createMock(\Shipard\Core\Config\ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', [
                'vehicle.fuel' => ['name' => 'Fuel'],
                'it.software'  => ['name' => 'Software'],
            ]],
        ]);
        $doc = $this->doc();
        $doc->setConfig($config);
        return $doc;
    }

    public function testValidateContentTagsKnownKeysPass(): void
    {
        $data = ['name' => 'PHM', 'item_kind' => 1, 'unit' => 1,
                 'content_tags' => ['vehicle.fuel', 'it.software']];

        $this->assertTrue($this->docWithTaxonomy()->validate($data)->isValid());
    }

    public function testValidateContentTagsUnknownKeyFails(): void
    {
        $data = ['name' => 'PHM', 'item_kind' => 1, 'unit' => 1,
                 'content_tags' => ['vehicle.fuel', 'bogus.tag']];
        $result = $this->docWithTaxonomy()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('content_tags', $columns);
    }

    public function testValidateContentTagsMustBeListOfStrings(): void
    {
        $data = ['name' => 'PHM', 'item_kind' => 1, 'unit' => 1,
                 'content_tags' => ['vehicle.fuel' => true]];
        $result = $this->docWithTaxonomy()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('content_tags', $columns);
    }

    public function testValidateContentTagsWithoutTaxonomySkipsKeyCheck(): void
    {
        // Chybějící compiled config (např. CLI kontext) → validace klíčů se
        // přeskočí, tvar (list stringů) se pořád vynucuje.
        $data = ['name' => 'PHM', 'item_kind' => 1, 'unit' => 1,
                 'content_tags' => ['whatever.tag']];

        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testBeforeSaveEncodesContentTagsToJson(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);
        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['name' => 'X', 'item_kind' => 1, 'unit' => 1,
                 'content_tags' => ['vehicle.fuel', 'it.software']];
        $doc->beforeSave($data);

        $this->assertSame('["vehicle.fuel","it.software"]', $data['content_tags']);
    }

    public function testBeforeSaveEmptyContentTagsBecomesNull(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);
        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['name' => 'X', 'item_kind' => 1, 'unit' => 1, 'content_tags' => []];
        $doc->beforeSave($data);

        $this->assertNull($data['content_tags']);
    }
}
