<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Items;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end coverage for ItemApplier against a live DB. Run with
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id> vendor/bin/phpunit
 * --testsuite Integration`.
 *
 * Each fixture in tests/Fixtures/Exchange/items/ exercises one of the
 * spec §10 paths. Test data is namespaced with the `IT-EX` prefix in
 * codes / names / company_ids so tearDown can purge it cleanly.
 */
class ItemsApplyE2ETest extends IntegrationTestCase
{
    private const TEST_CODE_PREFIX = 'IT-EX-';
    private const TEST_COMPANY_ID_PREFIX = 'IT-EX-';
    private const TEST_NAME_PREFIX = 'IT-EX ';

    private ConfigRuntime $config;
    private ItemApplier $applier;
    private PersonApplier $personApplier;

    /** @var list<int> */
    private array $createdItemIds = [];
    /** @var list<int> */
    private array $createdKindIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        $row = $this->db->fetchRow(
            "SHOW COLUMNS FROM economy_items LIKE 'source_kind'",
        );
        if ($row === null) {
            $this->markTestSkipped('DS missing source_kind on economy_items — run ds-upgrade.');
        }

        $this->personApplier = PersonApplier::create(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
        );
        $this->applier = ItemApplier::create(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
            $this->personApplier,
        );
    }

    protected function tearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        // Targeted cleanup by id.
        foreach ($this->createdItemIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE item = %i', $id);
            $dibi->query('DELETE FROM economy_items WHERE id = %i', $id);
        }
        foreach ($this->createdKindIds as $id) {
            $dibi->query('DELETE FROM economy_items_kinds WHERE id = %i', $id);
        }
        foreach ($this->createdPersonIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }

        // Belt-and-suspenders cleanup for rows left by failed tests.
        $itemRows = $dibi->fetchAll(
            'SELECT id FROM economy_items WHERE code LIKE %s',
            self::TEST_CODE_PREFIX . '%',
        );
        foreach ($itemRows as $row) {
            $id = (int) $row['id'];
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE item = %i', $id);
            $dibi->query('DELETE FROM economy_items WHERE id = %i', $id);
        }
        $kindRows = $dibi->fetchAll(
            'SELECT id FROM economy_items_kinds WHERE name LIKE %s AND system_code IS NULL',
            self::TEST_NAME_PREFIX . '%',
        );
        foreach ($kindRows as $row) {
            $dibi->query('DELETE FROM economy_items_kinds WHERE id = %i', (int) $row['id']);
        }
        $personRows = $dibi->fetchAll(
            'SELECT id FROM base_persons_persons WHERE company_id LIKE %s',
            self::TEST_COMPANY_ID_PREFIX . '%',
        );
        foreach ($personRows as $row) {
            $id = (int) $row['id'];
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }

        parent::tearDown();
    }

    // ── service_create_happy ──────────────────────────────────────────────

    public function testServiceCreateHappy(): void
    {
        // Pre-seed a supplier partner used by the supplierCodes mapping.
        $supplierId = $this->seedCompany($this->uniqueCompanyId(), 'IT-EX HappyPath Supplier s.r.o.');

        $payload = $this->loadFixture('service_create_happy.json');
        $payload['code'] = $this->uniqueCode('SVC');
        $payload['supplierCodes'] = [[
            'supplier' => ['companyId' => $this->companyIdOf($supplierId)],
            'supplierCode' => 'IT-EX-HAPPY-001',
            'supplierName' => 'Happy Path Service',
        ]];

        $result = $this->applier->apply($payload);

        $this->assertTrue(
            $result->success,
            'Apply failed: ' . ($result->errorCode ?? '') . ': ' . ($result->errorMessage ?? '')
                . ' issues=' . json_encode($result->canonical['_resolve']['issues'] ?? []),
        );
        $itemId = $result->savedId;
        $this->assertNotNull($itemId);
        $this->createdItemIds[] = $itemId;

        $item = $this->db->fetchRow('SELECT * FROM economy_items WHERE id = %i', $itemId);
        $this->assertNotNull($item);
        $this->assertSame($payload['code'], $item['code']);
        $this->assertStringStartsWith(self::TEST_NAME_PREFIX, $item['name']);
        $this->assertSame(40, (int) $item['docState']);
        $this->assertSame(0, (int) $item['item_type'], 'item_type denormalized from `service` kind = 0');
        $this->assertEqualsWithDelta(1500.0, (float) $item['sales_price_no_vat'], 0.001);

        // Supplier code mapping
        $mappings = $this->db->fetchAll(
            'SELECT * FROM economy_items_supplier_codes WHERE item = %i',
            $itemId,
        );
        $this->assertCount(1, $mappings);
        $this->assertSame($supplierId, (int) $mappings[0]['person']);
        $this->assertSame('IT-EX-HAPPY-001', $mappings[0]['supplier_code']);

        // Lineage
        $this->assertSame('import.oldShipard', $item['source_kind']);
        $this->assertSame('12345', $item['source_ref']);
        $this->assertNotNull($item['source_imported_at']);
    }

    // ── stock_create_with_kind_canCreate ──────────────────────────────────

    public function testStockCreateWithKindCanCreate(): void
    {
        $payload = $this->loadFixture('stock_create_with_kind_canCreate.json');
        $payload['code'] = $this->uniqueCode('STK');
        // Make the kind name unique enough not to collide with leftover data.
        $payload['kind']['name'] = self::TEST_NAME_PREFIX . 'Kind ' . substr(uniqid(), -6);

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");

        $itemId = $result->savedId;
        $this->createdItemIds[] = $itemId;

        $item = $this->db->fetchRow('SELECT * FROM economy_items WHERE id = %i', $itemId);
        $this->assertSame(1, (int) $item['item_type'], 'item_type denormalized from new stock kind = 1');

        // New kind created
        $kind = $this->db->fetchRow(
            'SELECT * FROM economy_items_kinds WHERE id = %i',
            (int) $item['item_kind'],
        );
        $this->assertSame($payload['kind']['name'], $kind['name']);
        $this->assertSame(1, (int) $kind['item_type']);
        $this->assertNull($kind['system_code'], 'side-created kind must have NULL system_code');
        $this->createdKindIds[] = (int) $kind['id'];
    }

    // ── item_update_mergeAdd ──────────────────────────────────────────────

    public function testItemUpdateMergeAdd(): void
    {
        // Seed existing item with non-empty description and empty sales_price_no_vat.
        $existingCode = $this->uniqueCode('MA');
        $kindId = $this->lookupKindId('service');
        $unitId = $this->lookupUnitId('hr');
        $existingId = $this->seedItem([
            'code'               => $existingCode,
            'name'               => self::TEST_NAME_PREFIX . 'Pre-existing MergeAdd Item',
            'description'        => 'Existing description — keep me',
            'item_kind'          => $kindId,
            'unit'               => $unitId,
            'sales_price_no_vat' => null,
        ]);

        $payload = $this->loadFixture('item_update_mergeAdd.json');
        $payload['code'] = $existingCode;

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame($existingId, $result->savedId);

        $item = $this->db->fetchRow('SELECT * FROM economy_items WHERE id = %i', $existingId);
        // mergeAdd: existing description NOT overwritten
        $this->assertSame('Existing description — keep me', $item['description']);
        // mergeAdd: empty price filled
        $this->assertEqualsWithDelta(999.0, (float) $item['sales_price_no_vat'], 0.001);
    }

    // ── item_update_fullSync ──────────────────────────────────────────────

    public function testItemUpdateFullSync(): void
    {
        $existingCode = $this->uniqueCode('FS');
        $kindId = $this->lookupKindId('service');
        $unitId = $this->lookupUnitId('hr');
        $existingId = $this->seedItem([
            'code'               => $existingCode,
            'name'               => self::TEST_NAME_PREFIX . 'Pre-existing FullSync Item',
            'description'        => 'Old description — must be overwritten',
            'item_kind'          => $kindId,
            'unit'               => $unitId,
            'sales_price_no_vat' => 1.00,
        ]);

        // Pre-existing supplier mapping that is NOT in the payload — fullSync
        // for items must NOT close it (spec §6.4).
        $supplierId = $this->seedCompany($this->uniqueCompanyId(), 'IT-EX FullSync Untouched Supplier');
        $orphanMappingId = $this->seedSupplierCode(
            $supplierId, $existingId, 'IT-EX-FS-ORPH', 'Orphan mapping',
        );

        $payload = $this->loadFixture('item_update_fullSync.json');
        $payload['code'] = $existingCode;

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame($existingId, $result->savedId);

        $item = $this->db->fetchRow('SELECT * FROM economy_items WHERE id = %i', $existingId);
        // fullSync: description overwritten
        $this->assertSame('NEW description — must overwrite', $item['description']);
        $this->assertEqualsWithDelta(2500.0, (float) $item['sales_price_no_vat'], 0.001);

        // Orphan supplier-code mapping must still exist — fullSync has no
        // closing semantics for the items sub-collection.
        $orphan = $this->db->fetchRow(
            'SELECT id FROM economy_items_supplier_codes WHERE id = %i', $orphanMappingId,
        );
        $this->assertNotNull($orphan, 'fullSync must NOT delete supplier-code mappings');
    }

    // ── item_supplier_unknown ─────────────────────────────────────────────

    public function testSupplierUnknownSavesItemWithoutMapping(): void
    {
        $payload = $this->loadFixture('item_supplier_unknown.json');
        $payload['code'] = $this->uniqueCode('SUNK');
        $payload['supplierCodes'][0]['supplier']['companyId'] = $this->uniqueCompanyId();
        // Important: no `_resolve.supplierCodes[].userAction` → default SKIP.

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $itemId = $result->savedId;
        $this->createdItemIds[] = $itemId;

        // Item saved, no mapping inserted.
        $mappings = $this->db->fetchAll(
            'SELECT * FROM economy_items_supplier_codes WHERE item = %i', $itemId,
        );
        $this->assertCount(0, $mappings, 'supplier_unknown → mapping skipped');

        // _resolve should report the warning.
        $issues = $result->canonical['_resolve']['issues'] ?? [];
        $codes = array_column($issues, 'code');
        $this->assertContains('supplier_unknown', $codes);
    }

    // ── item_supplier_canCreate ───────────────────────────────────────────

    public function testSupplierCanCreateAutocreatesPartnerAndInsertsMapping(): void
    {
        $payload = $this->loadFixture('item_supplier_canCreate.json');
        $payload['code'] = $this->uniqueCode('SCC');
        $companyId = $this->uniqueCompanyId();
        $payload['supplierCodes'][0]['supplier']['companyId'] = $companyId;
        $payload['supplierCodes'][0]['supplier']['vatId'] = 'CZ' . $companyId;

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $itemId = $result->savedId;
        $this->createdItemIds[] = $itemId;

        // Partner created — must show up in base_persons_persons.
        $person = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE company_id = %s', $companyId,
        );
        $this->assertNotNull($person, 'autocreate partner must produce a row');
        $this->createdPersonIds[] = (int) $person['id'];

        // Mapping wired.
        $mappings = $this->db->fetchAll(
            'SELECT * FROM economy_items_supplier_codes WHERE item = %i', $itemId,
        );
        $this->assertCount(1, $mappings);
        $this->assertSame((int) $person['id'], (int) $mappings[0]['person']);
        $this->assertSame('AUT-X1', $mappings[0]['supplier_code']);
    }

    // ── item_code_conflict ────────────────────────────────────────────────
    //
    // In practice, ItemResolver's strategy 1 (ourCode match) fires before
    // any other key — so a payload with `code = X` against an existing item
    // with the same code always resolves to `matched, matchedBy=ourCode`.
    // Combined with `createOnly`, that produces `item_exists`. The
    // `code_conflict` issue stays as defence-in-depth for future resolver
    // changes that may bypass strategy 1 (e.g. stronger fuzzy keys).

    public function testCodeConflictUnderCreateOnlyRejectsAsItemExists(): void
    {
        $conflictCode = $this->uniqueCode('CC');
        $kindId = $this->lookupKindId('service');
        $unitId = $this->lookupUnitId('hr');
        $this->seedItem([
            'code'      => $conflictCode,
            'name'      => self::TEST_NAME_PREFIX . 'CodeConflict Pre-existing',
            'item_kind' => $kindId,
            'unit'      => $unitId,
        ]);

        $payload = $this->loadFixture('item_code_conflict.json');
        $payload['code'] = $conflictCode;

        $result = $this->applier->apply($payload);
        $this->assertFalse($result->success);
        $this->assertSame('item_exists', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    // ── item_kind_itemTypeFallback ────────────────────────────────────────

    public function testKindItemTypeFallbackEmitsWarning(): void
    {
        $payload = $this->loadFixture('item_kind_itemTypeFallback.json');
        $payload['code'] = $this->uniqueCode('ITF');

        $result = $this->applier->apply($payload);
        $this->assertTrue($result->success, "Apply failed: {$result->errorCode}: {$result->errorMessage}");
        $itemId = $result->savedId;
        $this->createdItemIds[] = $itemId;

        // Item resolved to seeded `service` kind.
        $item = $this->db->fetchRow('SELECT * FROM economy_items WHERE id = %i', $itemId);
        $this->assertSame(0, (int) $item['item_type']);
        $serviceKindId = $this->lookupKindId('service');
        $this->assertSame($serviceKindId, (int) $item['item_kind']);

        // Warning emitted.
        $issues = $result->canonical['_resolve']['issues'] ?? [];
        $codes = array_column($issues, 'code');
        $this->assertContains('kind_inferred_from_itemType', $codes);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Exchange/items/' . $name;
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read fixture: {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON in fixture: {$path}");
        }
        return $decoded;
    }

    private function uniqueCode(string $tag): string
    {
        return self::TEST_CODE_PREFIX . $tag . '-' . substr((string) (microtime(true) * 10000), -8);
    }

    private function uniqueCompanyId(): string
    {
        return self::TEST_COMPANY_ID_PREFIX . substr((string) (microtime(true) * 10000), -8);
    }

    private function companyIdOf(int $personId): string
    {
        $row = $this->db->fetchRow('SELECT company_id FROM base_persons_persons WHERE id = %i', $personId);
        return (string) $row['company_id'];
    }

    private function lookupKindId(string $systemCode): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_items_kinds WHERE system_code = %s', $systemCode,
        );
        if ($row === null) {
            throw new \RuntimeException("Seeded kind `{$systemCode}` not found — run ds-upgrade.");
        }
        return (int) $row['id'];
    }

    private function lookupUnitId(string $systemCode): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_units WHERE system_code = %s', $systemCode,
        );
        if ($row === null) {
            throw new \RuntimeException("Seeded unit `{$systemCode}` not found — run ds-upgrade.");
        }
        return (int) $row['id'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedItem(array $payload): int
    {
        $payload = array_merge([
            'docState'     => 40,
            'docStateMain' => 2,
            'item_type'    => 0,
        ], $payload);
        $this->db->getDibiConnection()->insert('economy_items', $payload)->execute();
        $id = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdItemIds[] = $id;
        return $id;
    }

    private function seedCompany(string $companyId, string $fullName): int
    {
        $payload = [
            'person_id'    => 'IT' . substr(uniqid(), -8),
            'person_type'  => 2,
            'full_name'    => $fullName,
            'last_name'    => $fullName,
            'first_name'   => '',
            'company_id'   => $companyId,
            'docState'     => 40,
            'docStateMain' => 3,
        ];
        $this->db->getDibiConnection()->insert('base_persons_persons', $payload)->execute();
        $id = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdPersonIds[] = $id;
        return $id;
    }

    private function seedSupplierCode(
        int $personId,
        int $itemId,
        string $supplierCode,
        ?string $supplierName,
    ): int {
        $this->db->getDibiConnection()->insert('economy_items_supplier_codes', [
            'person'        => $personId,
            'item'          => $itemId,
            'supplier_code' => $supplierCode,
            'supplier_name' => $supplierName,
            'created'       => new \DateTimeImmutable(),
        ])->execute();
        return (int) $this->db->getDibiConnection()->getInsertId();
    }
}
