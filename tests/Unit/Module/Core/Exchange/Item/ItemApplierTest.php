<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Item;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Item\ItemFlowResolver;
use Shipard\Module\Core\Exchange\Item\ItemResolveResult;
use Shipard\Module\Core\Exchange\Item\ItemValidator;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Testable applier — intercepts executeSql() so SQL calls (mapping
 * INSERTs, lineage UPDATEs) can be asserted without hitting the DB.
 */
class TestableItemApplier extends ItemApplier
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): mixed
    {
        $this->sqlCalls[] = $args;
        return null;
    }
}

class ItemApplierTest extends TestCase
{
    // ── validate() ─────────────────────────────────────────────────────────

    public function testValidateRejectsSchemaInvalidPayload(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.items.item',
            // missing formatVersion, name, unit
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame(400, $result->statusCode);
    }

    public function testValidateRejectsKindMissingHints(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate($this->validPayload([
            'kind' => ['code' => '', 'name' => null, 'itemType' => null],
        ]));
        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
    }

    public function testValidateAcceptsMinimalPayload(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate($this->validPayload());
        $this->assertTrue($result->success);
    }

    // ── apply() — happy path create ───────────────────────────────────────

    public function testApplyCreatesNewItem(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'Konzultace IT']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(function ($payload) {
                // code is null in test payload → applier drops the key
                return !array_key_exists('code', $payload)
                    && ($payload['name'] ?? null) === 'Konzultace IT'
                    && ($payload['item_kind'] ?? null) === 5
                    && ($payload['unit'] ?? null) === 3
                    && !array_key_exists('item_type', $payload);
            }))
            ->willReturn(DocumentResult::ok(['id' => 42]));

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);

        $result = $applier->apply($this->validPayload());
        $this->assertTrue($result->success, "Expected success; got {$result->errorCode}: {$result->errorMessage}");
        $this->assertSame(42, $result->savedId);
        $this->assertSame(42, $result->canonical['savedItemId']);
    }

    public function testApplyPreservesExplicitCode(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(fn($p) => ($p['code'] ?? null) === 'K-001'))
            ->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);

        $result = $applier->apply($this->validPayload(['code' => 'K-001']));
        $this->assertTrue($result->success);
    }

    // ── apply() — createOnly + matched → 409 item_exists ───────────────────

    public function testApplyCreateOnlyMatchedRejectsWith409(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(99, 'ourCode'),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $db->expects($this->never())->method('begin');

        $applier = $this->buildApplier(db: $db, resolver: $resolver);

        $result = $applier->apply($this->validPayload([
            'applyOptions' => ['mergeStrategy' => 'createOnly'],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('item_exists', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    // ── apply() — code_conflict → 409 ─────────────────────────────────────

    public function testApplyCodeConflictRejectsWith409(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
            issues: [[
                'severity' => 'error',
                'path'     => 'code',
                'code'     => 'code_conflict',
                'message'  => 'Kód „K-001" je již použit u jiné položky (id=99).',
            ]],
        );

        $db->expects($this->never())->method('begin');

        $applier = $this->buildApplier(db: $db, resolver: $resolver);

        $result = $applier->apply($this->validPayload(['code' => 'K-001']));

        $this->assertFalse($result->success);
        $this->assertSame('code_conflict', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    // ── apply() — updateHeader uses existing id, skips supplierCodes ──────

    public function testApplyUpdateHeaderUpdatesMatchedAndSkipsSupplierCodes(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(50, 'ourCode'),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
            supplierCodes: [[
                'index' => 0,
                'supplier' => ['status' => 'matched', 'matchedId' => 100],
                'status' => 'canCreate',  // would normally INSERT
                'supplierCode' => 'X-1',
                'supplierName' => null,
            ]],
        );

        $db->method('fetch')->willReturn(new Row([
            'id' => 50, 'name' => 'Old', 'description' => '', 'item_kind' => 5, 'unit' => 3,
        ]));

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(fn($p) =>
                ($p['id'] ?? null) === 50
                && ($p['name'] ?? null) === 'Konzultace IT'
            ))
            ->willReturn(DocumentResult::ok(['id' => 50]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $result = $applier->apply($this->validPayload([
            'applyOptions' => ['mergeStrategy' => 'updateHeader'],
        ]));

        $this->assertTrue($result->success);
        $this->assertSqlAbsent($applier->sqlCalls, 'economy_items_supplier_codes');
    }

    // ── apply() — mergeAdd fill rules ─────────────────────────────────────

    public function testApplyMergeAddFillsOnlyEmptyColumns(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(50, 'ourCode'),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        // Existing row: description has data ("Old"), sku is empty.
        $db->method('fetch')->willReturn(new Row([
            'id' => 50, 'name' => 'Old', 'description' => 'Existing description',
            'sku' => '', 'item_kind' => 5, 'unit' => 3,
        ]));

        $captured = null;
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturnCallback(
            function ($payload) use (&$captured): DocumentResult {
                $captured = $payload;
                return DocumentResult::ok(['id' => 50]);
            },
        );

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $result = $applier->apply($this->validPayload([
            'applyOptions' => ['mergeStrategy' => 'mergeAdd'],
            'description'  => 'NEW description',
            'sku'          => 'NEW-SKU',
        ]));

        $this->assertTrue($result->success);
        // mergeAdd: description in DB is non-empty → not overwritten.
        $this->assertSame('Existing description', $captured['description']);
        // sku in DB is empty → filled from payload.
        $this->assertSame('NEW-SKU', $captured['sku']);
    }

    public function testApplyFullSyncOverwritesAllColumns(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::matched(50, 'ourCode'),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $db->method('fetch')->willReturn(new Row([
            'id' => 50, 'name' => 'Old', 'description' => 'Old description',
            'sku' => 'OLD-SKU', 'item_kind' => 5, 'unit' => 3,
        ]));

        $captured = null;
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturnCallback(
            function ($payload) use (&$captured): DocumentResult {
                $captured = $payload;
                return DocumentResult::ok(['id' => 50]);
            },
        );

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $applier->apply($this->validPayload([
            'applyOptions' => ['mergeStrategy' => 'fullSync'],
            'description'  => 'NEW description',
            'sku'          => 'NEW-SKU',
        ]));

        $this->assertSame('NEW description', $captured['description']);
        $this->assertSame('NEW-SKU', $captured['sku']);
        $this->assertSame('Konzultace IT', $captured['name']);
    }

    // ── apply() — kind canCreate ───────────────────────────────────────────

    public function testApplyKindCanCreateWithoutUserActionRejectsWith422(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::canCreate(['name' => 'New kind', 'item_type' => 1]),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $db->expects($this->never())->method('begin');

        $applier = $this->buildApplier(db: $db, resolver: $resolver);
        $result = $applier->apply($this->validPayload([
            'kind' => ['name' => 'New kind', 'itemType' => 1],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
    }

    public function testApplyKindCanCreateWithUserActionSideCreatesKind(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::canCreate(['name' => 'New kind', 'item_type' => 1, 'docState' => 40]),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $kindsGateway = $this->createMock(TransactionlessTableGateway::class);
        $kindsGateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(fn($p) => ($p['name'] ?? null) === 'New kind'))
            ->willReturn(DocumentResult::ok(['id' => 77]));

        $itemsGateway = $this->createMock(TransactionlessTableGateway::class);
        $itemsGateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(fn($p) => ($p['item_kind'] ?? null) === 77))
            ->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(
            db: $db, resolver: $resolver,
            itemsGateway: $itemsGateway, kindsGateway: $kindsGateway,
        );

        $result = $applier->apply($this->validPayload([
            'kind' => ['name' => 'New kind', 'itemType' => 1],
            '_resolve' => ['kind' => ['userAction' => 'create']],
        ]));

        $this->assertTrue($result->success, "Got {$result->errorCode}: {$result->errorMessage}");
    }

    // ── apply() — supplier mapping flows ──────────────────────────────────

    public function testApplyMatchedSupplierMissingMappingInsertsIgnoreRow(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
            supplierCodes: [[
                'index'        => 0,
                'supplier'     => ['status' => 'matched', 'matchedId' => 100],
                'status'       => 'canCreate',
                'supplierCode' => 'KONZ-001',
                'supplierName' => 'Konzultace IT',
            ]],
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $result = $applier->apply($this->validPayload([
            'supplierCodes' => [[
                'supplier' => ['companyId' => '12345678'],
                'supplierCode' => 'KONZ-001',
            ]],
        ]));

        $this->assertTrue($result->success);
        $this->assertSqlContains($applier->sqlCalls, 'INSERT IGNORE', 'economy_items_supplier_codes');
    }

    public function testApplySupplierCanCreateWithoutActionSkipsMapping(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
            supplierCodes: [[
                'index'        => 0,
                'supplier'     => ['status' => 'canCreate'],
                'status'       => 'skipped',
                'userAction'   => null,
                'issue'        => 'supplier_unknown',
                'supplierCode' => 'BETA-X',
            ]],
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $result = $applier->apply($this->validPayload([
            'supplierCodes' => [[
                'supplier' => ['name' => 'Beta'],
                'supplierCode' => 'BETA-X',
            ]],
        ]));

        $this->assertTrue($result->success);
        $this->assertSqlAbsent($applier->sqlCalls, 'economy_items_supplier_codes');
    }

    public function testApplySupplierCanCreateWithUserActionDelegatesToPersonApplier(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
            supplierCodes: [[
                'index'        => 0,
                'supplier'     => ['status' => 'canCreate'],
                'status'       => 'skipped',
                'userAction'   => null,
                'issue'        => 'supplier_unknown',
                'supplierCode' => 'BETA-X',
            ]],
        );

        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->expects($this->once())
            ->method('apply')
            ->with($this->callback(fn($p) =>
                ($p['format'] ?? null) === 'shpd.persons.person'
                && ($p['personType'] ?? null) === 'company'
                && ($p['name']['fullName'] ?? null) === 'Beta s.r.o.'
            ))
            ->willReturn(ApplyResult::ok(['_resolve' => ['header' => ['personId' => 999]]], savedId: 999));

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(
            db: $db, resolver: $resolver, itemsGateway: $gateway, personApplier: $personApplier,
        );

        $result = $applier->apply($this->validPayload([
            'supplierCodes' => [[
                'supplier' => ['name' => 'Beta s.r.o.', 'companyId' => '87654321'],
                'supplierCode' => 'BETA-X',
            ]],
            '_resolve' => [
                'supplierCodes' => [['index' => 0, 'userAction' => 'create']],
            ],
        ]));

        $this->assertTrue($result->success, "Got {$result->errorCode}: {$result->errorMessage}");
        $this->assertSqlContains($applier->sqlCalls, 'INSERT IGNORE', 'economy_items_supplier_codes');
    }

    // ── apply() — lineage ─────────────────────────────────────────────────

    public function testApplyWritesLineageWhenSourceKindSupplied(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $result = $applier->apply($this->validPayload([
            'source' => ['kind' => 'import.oldShipard', 'registryRef' => '12345'],
        ]));

        $this->assertTrue($result->success);
        $this->assertSqlContains($applier->sqlCalls, 'source_kind');
    }

    public function testApplySkipsLineageWhenSourceKindMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $result = $applier->apply($this->validPayload());

        $this->assertTrue($result->success);
        $this->assertSqlAbsent($applier->sqlCalls, 'source_kind');
    }

    // ── apply() — annotateApplied for canCreate header ────────────────────

    public function testApplyAnnotatesHeaderAsMatchedAfterCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $resolver = $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(db: $db, resolver: $resolver, itemsGateway: $gateway);
        $result = $applier->apply($this->validPayload());

        $this->assertTrue($result->success);
        $this->assertSame('matched', $result->canonical['_resolve']['header']['status']);
        $this->assertSame(42, $result->canonical['_resolve']['header']['itemId']);
        $this->assertSame('created', $result->canonical['_resolve']['header']['matchedBy']);
        $this->assertSame('applied', $result->canonical['_resolve']['summary']['status']);
    }

    // ── SQL assert helpers ────────────────────────────────────────────────

    /**
     * @param list<array> $sqlCalls
     */
    private function assertSqlContains(array $sqlCalls, string ...$needles): void
    {
        foreach ($sqlCalls as $call) {
            $sql = $this->flattenSql($call);
            $hit = true;
            foreach ($needles as $needle) {
                if (!str_contains($sql, $needle)) { $hit = false; break; }
            }
            if ($hit) return;
        }
        $this->fail('Expected SQL call containing: ' . implode(' + ', $needles));
    }

    /**
     * @param list<array> $sqlCalls
     */
    private function assertSqlAbsent(array $sqlCalls, string $needle): void
    {
        foreach ($sqlCalls as $call) {
            $sql = $this->flattenSql($call);
            if (str_contains($sql, $needle)) {
                $this->fail("Unexpected SQL containing `{$needle}`: {$sql}");
            }
        }
    }

    private function flattenSql(array $call): string
    {
        $parts = [];
        foreach ($call as $arg) {
            if (is_string($arg)) {
                $parts[] = $arg;
            } elseif (is_array($arg)) {
                foreach ($arg as $k => $v) {
                    $parts[] = (string) $k;
                    if (is_scalar($v)) $parts[] = (string) $v;
                }
            }
        }
        return implode(' ', $parts);
    }

    // ── Builders ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'format'        => 'shpd.items.item',
            'formatVersion' => '1.0',
            'name'          => 'Konzultace IT',
            'unit'          => 'h',
            'kind'          => ['code' => 'service'],
        ];
    }

    private function stubResolver(
        ResolveResult $header,
        ResolveResult $kind,
        ResolveResult $unit,
        array $supplierCodes = [],
        array $issues = [],
    ): ItemFlowResolver {
        $resolver = $this->createMock(ItemFlowResolver::class);
        $resolver->method('resolve')->willReturn(new ItemResolveResult(
            header: $header,
            kind: $kind,
            unit: $unit,
            supplierCodes: $supplierCodes,
            issues: $issues,
        ));
        return $resolver;
    }

    private function buildApplier(
        ?Connection $db = null,
        ?ItemFlowResolver $resolver = null,
        ?TransactionlessTableGateway $itemsGateway = null,
        ?TransactionlessTableGateway $kindsGateway = null,
        ?PersonApplier $personApplier = null,
        ?AccountResolver $accountResolver = null,
    ): TestableItemApplier {
        $db ??= $this->createMock(Connection::class);
        $resolver ??= $this->stubResolver(
            header: ResolveResult::canCreate(['name' => 'X']),
            kind:   ResolveResult::matched(5, 'system_code'),
            unit:   ResolveResult::matched(3, 'alias'),
        );
        $itemsGateway ??= $this->createMock(TransactionlessTableGateway::class);
        $kindsGateway ??= $this->createMock(TransactionlessTableGateway::class);
        $unitResolver = $this->createMock(UnitResolver::class);
        $unitResolver->method('resolve')->willReturn(ResolveResult::matched(1, 'systemCode'));

        return new TestableItemApplier(
            db: $db,
            config: $this->createMock(ConfigRuntime::class),
            itemsGateway: $itemsGateway,
            kindsGateway: $kindsGateway,
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            itemValidator: new ItemValidator(),
            flowResolver: $resolver,
            unitResolver: $unitResolver,
            personApplier: $personApplier,
            accountResolver: $accountResolver,
        );
    }

    // ── accountingAccount + contentTags (datasety, #40) ────────────────────

    public function testApplyStoresAccountingAccountAndContentTags(): void
    {
        $account = $this->createMock(AccountResolver::class);
        $account->expects($this->once())->method('resolve')->with('518100')->willReturn(9);

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(static fn(array $p): bool => ($p['accounting_account'] ?? null) === 9
                && ($p['content_tags'] ?? null) === ['it.software', 'services.accounting']))
            ->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(itemsGateway: $gateway, accountResolver: $account);
        $result = $applier->apply($this->validPayload([
            'accountingAccount' => '518100',
            'contentTags'       => ['it.software', 'services.accounting'],
        ]));

        $this->assertTrue($result->success, "Expected success; got {$result->errorCode}: {$result->errorMessage}");
    }

    public function testUnknownAccountingAccountIsWarningNotError(): void
    {
        $account = $this->createMock(AccountResolver::class);
        $account->method('resolve')->willReturn(null);

        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(static fn(array $p): bool => !array_key_exists('accounting_account', $p)))
            ->willReturn(DocumentResult::ok(['id' => 42]));

        $applier = $this->buildApplier(itemsGateway: $gateway, accountResolver: $account);
        $result = $applier->apply($this->validPayload(['accountingAccount' => '999999']));

        $this->assertTrue($result->success);
        $codes = array_column($result->canonical['_resolve']['issues'] ?? [], 'code');
        $this->assertContains('account_not_found', $codes);
    }

    public function testEmptyContentTagsAreNotWritten(): void
    {
        $gateway = $this->createMock(TransactionlessTableGateway::class);
        $gateway->expects($this->once())
            ->method('saveDocument')
            ->with($this->callback(static fn(array $p): bool => !array_key_exists('content_tags', $p)))
            ->willReturn(DocumentResult::ok(['id' => 42]));

        $result = $this->buildApplier(itemsGateway: $gateway)->apply($this->validPayload(['contentTags' => []]));
        $this->assertTrue($result->success);
    }
}
