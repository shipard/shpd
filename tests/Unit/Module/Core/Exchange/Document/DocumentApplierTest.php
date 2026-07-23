<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\NumberSeriesNotFoundException;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Testable applier with `executeSql` no-op'd. `Dibi\Connection::query()` is
 * final and cannot be mocked, but the applier wraps it in a protected
 * method so subclasses can intercept.
 */
class TestableDocumentApplier extends DocumentApplier
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->sqlCalls[] = $args;
    }
}

class DocumentApplierTest extends TestCase
{
    private function buildApplier(
        ?Connection $db = null,
        ?PartyResolver $party = null,
        ?ItemResolver $item = null,
        ?UnitResolver $unit = null,
        ?VatCodeResolver $vat = null,
        ?BankAccountResolver $bank = null,
        ?TransactionlessTableGateway $heads = null,
        ?TransactionlessTableGateway $persons = null,
        ?TransactionlessTableGateway $items = null,
        ?AccountResolver $account = null,
    ): DocumentApplier {
        $db ??= $this->createMock(Connection::class);
        $party ??= $this->createMock(PartyResolver::class);
        $item ??= $this->createMock(ItemResolver::class);
        $unit ??= $this->createMock(UnitResolver::class);
        $vat ??= $this->createMock(VatCodeResolver::class);
        $bank ??= $this->createMock(BankAccountResolver::class);
        $heads ??= $this->createMock(TransactionlessTableGateway::class);
        $persons ??= $this->createMock(TransactionlessTableGateway::class);
        $items ??= $this->createMock(TransactionlessTableGateway::class);
        $account ??= $this->createMock(AccountResolver::class);

        return new TestableDocumentApplier(
            db: $db,
            config: $this->createMock(ConfigRuntime::class),
            headsGateway: $heads,
            personsGateway: $persons,
            itemsGateway: $items,
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: $party,
            itemResolver: $item,
            unitResolver: $unit,
            vatCodeResolver: $vat,
            bankAccountResolver: $bank,
            accountResolver: $account,
        );
    }

    public function testValidateRejectsSchemaInvalidPayload(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.docs.document',
            // missing formatVersion + docType
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame(400, $result->statusCode);
    }

    public function testValidateRejectsMissingRequiredFields(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
            // missing supplier + dates + rows
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $issues = $result->canonical['_resolve']['issues'] ?? [];
        $this->assertNotEmpty($issues);
    }

    public function testValidateAcceptsHappyFixture(): void
    {
        $applier = $this->buildApplier();
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->validate($payload);
        $this->assertTrue($result->success, 'Errors: ' . json_encode($result->canonical['_resolve']['issues'] ?? []));
    }

    public function testPreviewPopulatesResolveBlock(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $itemResolver = $this->createMock(ItemResolver::class);
        $itemResolver->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $itemResolver, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->preview($payload);

        $this->assertTrue($result->success);
        $resolve = $result->canonical['_resolve'];
        $this->assertSame('matched', $resolve['supplier']['status']);
        $this->assertSame(42, $resolve['supplier']['matchedId']);
        $this->assertSame('matched', $resolve['supplierBank']['status']);
        $this->assertSame(7, $resolve['supplierBank']['matchedId']);
        $this->assertSame('matched', $resolve['rows'][0]['item']['status']);
        $this->assertSame(18, $resolve['rows'][0]['item']['matchedId']);
        $this->assertSame('ok', $resolve['summary']['status']);
        // supplier + supplierBank + row[0].item + row[0].unit + row[0].vatCode = 5
        $this->assertSame(5, $resolve['summary']['matchedCount']);
    }

    public function testPreviewMarksCanCreateAsUnresolved(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Brand New s.r.o.']));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $itemResolver = $this->createMock(ItemResolver::class);
        $itemResolver->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::canCreate(['iban' => 'CZ...']));

        $applier = $this->buildApplier(party: $party, item: $itemResolver, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame('canCreate', $resolve['supplier']['status']);
        $this->assertSame('canCreate', $resolve['supplierBank']['status']);
        $this->assertSame('needsAttention', $resolve['summary']['status']);
        $this->assertGreaterThan(0, $resolve['summary']['unresolvedCount']);
    }

    public function testApplyFailsWithUnresolvedRequiredWhenUserActionMissing(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'X']));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
    }

    public function testApplyFailsWithConflictWhenUseExistingTargetGone(): void
    {
        $db = $this->createMock(Connection::class);
        // Reconcile probes whether base_persons_persons #99 exists → returns null
        $db->method('fetch')->willReturn(null);

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::ambiguous([
            ['id' => 99, 'name' => 'Maybe Acme', 'companyId' => '123'],
        ]));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(db: $db, party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload['_resolve'] = [
            'supplier' => ['userAction' => 'useExisting:99'],
        ];
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('conflict', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    public function testResolveOneAcceptsUseExistingOnArchivedTarget(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args;
            return new Row(['id' => 2468]); // záznam existuje — byť v archívu (70)
        });
        $applier = $this->buildApplier(db: $db);

        $plan = ['errorCode' => null, 'errorMessage' => null];
        $issues = [];
        $ref = new \ReflectionMethod($applier, 'resolveOne');
        $res = $ref->invokeArgs($applier, [
            'customer',
            ['status' => 'notFound'],
            'useExisting:2468',
            'base_persons_persons',
            &$plan,
            &$issues,
        ]);

        $this->assertSame(2468, $res['id']);
        $this->assertNull($plan['errorCode']);
        // Pin smí mířit i na archiv (70); odmítá se jen Smazáno (90).
        $this->assertContains(70, $captured);
        $this->assertNotContains(90, $captured);
    }

    public function testResolvePinAcceptsLinkableTargetAndWarnsWhenMissing(): void
    {
        // a) linkable cíl (např. archivovaná osoba) projde bez issues
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 77]));
        $applier = $this->buildApplier(db: $db);

        $issues = [];
        $ref = new \ReflectionMethod($applier, 'resolvePin');
        $this->assertSame(77, $ref->invokeArgs($applier, ['partner', 'useExisting:77', &$issues]));
        $this->assertSame([], $issues);

        // b) smazaný/neexistující cíl → null + warning (žádná tichá ztráta)
        $db2 = $this->createMock(Connection::class);
        $db2->method('fetch')->willReturn(null);
        $applier2 = $this->buildApplier(db: $db2);

        $issues2 = [];
        $ref2 = new \ReflectionMethod($applier2, 'resolvePin');
        $id = $ref2->invokeArgs($applier2, ['rows.0.partner', 'useExisting:99', &$issues2]);
        $this->assertNull($id);
        $this->assertCount(1, $issues2);
        $this->assertSame('warning', $issues2[0]['severity']);
        $this->assertSame('pin_target_missing', $issues2[0]['code']);
        $this->assertSame('rows.0.partner', $issues2[0]['path']);
    }

    public function testApplyFailsWithSchemaInvalidOnBrokenStructure(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->apply([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            // missing docType
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame(400, $result->statusCode);
    }

    public function testApplyFailsWithValidationFailedOnMissingIssueDate(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            \Shipard\Module\Core\Exchange\Resolve\ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat);

        $result = $applier->apply([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor', 'companyId' => '12345678'],
            // dates.issueDate is missing → validation_failed
            'rows' => [['rowKind' => 'item', 'item' => ['name' => 'X']]],
        ]);
        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
    }

    // ── autoCreateMode (Phase 2) ────────────────────────────────────────────

    /**
     * Helper to build a fully-stubbed canonical payload where every reference
     * is matched except `supplier` which is canCreate with a configurable
     * payload. Used for autoCreateMode tests.
     *
     * @param array<string, mixed> $supplierCreatePayload
     * @param array<string, mixed>|null $applyOptions
     * @return array<string, mixed>
     */
    private function payloadWithCanCreateSupplier(array $supplierCreatePayload, ?array $applyOptions = null): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        if ($applyOptions !== null) {
            $payload['applyOptions'] = $applyOptions;
        }
        return $payload;
    }

    /**
     * Build resolvers where supplier=canCreate with given payload, everything
     * else matched. Used across autoCreateMode tests.
     *
     * @param array<string, mixed> $supplierCreatePayload
     * @param array<string, mixed>|null $itemCreatePayload  if non-null, item is canCreate too
     */
    private function buildAutoCreateResolvers(
        array $supplierCreatePayload,
        ?array $itemCreatePayload = null,
    ): array {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate($supplierCreatePayload));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        if ($itemCreatePayload === null) {
            $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        } else {
            $item->method('resolve')->willReturn(ResolveResult::canCreate($itemCreatePayload));
        }

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'cz-110', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        return ['party' => $party, 'item' => $item, 'unit' => $unit, 'vat' => $vat, 'bank' => $bank];
    }

    public function testStrictModeIsDefaultAndRejectsAutoCreate(): void
    {
        $resolvers = $this->buildAutoCreateResolvers(['full_name' => 'X', 'company_id' => '12345678']);
        $applier = $this->buildApplier(
            party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
        );

        // No applyOptions → defaults to strict
        $payload = $this->payloadWithCanCreateSupplier(['full_name' => 'X']);
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testSafeModeAutoCreatesPartyWithCompanyId(): void
    {
        $resolvers = $this->buildAutoCreateResolvers([
            'person_type' => 2,
            'full_name'   => 'Brand New s.r.o.',
            'company_id'  => '12345678',
        ]);
        // Provide a stubbed personsGateway that returns the new id on saveDocument
        $persons = $this->createMock(TransactionlessTableGateway::class);
        $persons->expects($this->once())
            ->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 99]));

        // headsGateway succeeds too (mocking what would normally need DocDocument flow)
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('saveDocument')->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 1234]));

        // db: number_series / vat_registration lookups → null; query (supplier-code
        // mapping + lineage UPDATE) → no-op; begin/commit no-op via mock defaults.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('getInsertId')->willReturn(0);

        $applier = $this->buildApplier(
            db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
            heads: $heads, persons: $persons,
        );

        $payload = $this->payloadWithCanCreateSupplier(
            ['full_name' => 'X', 'company_id' => '12345678'],
            applyOptions: ['autoCreateMode' => 'safe'],
        );
        $result = $applier->apply($payload);

        $this->assertTrue(
            $result->success,
            "Expected success; errorCode={$result->errorCode} msg={$result->errorMessage}",
        );
        $this->assertSame(1234, $result->savedId);
    }

    public function testSafeModeRejectsPartyWithoutCompanyId(): void
    {
        $resolvers = $this->buildAutoCreateResolvers([
            'person_type' => 2,
            'full_name'   => 'Brand New s.r.o.',
            // company_id missing → guard fails
        ]);
        $applier = $this->buildApplier(
            party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
        );

        $payload = $this->payloadWithCanCreateSupplier(
            ['full_name' => 'X'],
            applyOptions: ['autoCreateMode' => 'safe'],
        );
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testSafeModeRejectsItemWithoutName(): void
    {
        // Supplier matched, item canCreate without name → safe guard fails on item
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::canCreate(['code' => 'X-001']));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched, matchedId: 0, matchedBy: 'cfgItem',
            createPayload: ['code' => 'cz-110', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $item, unit: $unit, vat: $vat, bank: $bank);

        $payload = $this->payloadWithCanCreateSupplier(
            [],
            applyOptions: ['autoCreateMode' => 'safe'],
        );
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testLiberalModeAutoCreatesEverything(): void
    {
        $resolvers = $this->buildAutoCreateResolvers(
            ['full_name' => 'X'], // no company_id — would fail safe guard
            itemCreatePayload: ['code' => 'X-001'], // no name — would fail safe guard
        );

        $persons = $this->createMock(TransactionlessTableGateway::class);
        $persons->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 99]));
        $items = $this->createMock(TransactionlessTableGateway::class);
        $items->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 50]));
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('saveDocument')
            ->willReturn(\Shipard\Core\Document\DocumentResult::ok(['id' => 1234]));

        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('getInsertId')->willReturn(0);
        // Liberal autocreate populates supplier-code mapping; mock db->query.

        $applier = $this->buildApplier(
            db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
            heads: $heads, persons: $persons, items: $items,
        );

        $payload = $this->payloadWithCanCreateSupplier(
            [],
            applyOptions: ['autoCreateMode' => 'liberal'],
        );
        $result = $applier->apply($payload);

        $this->assertTrue(
            $result->success,
            "Expected success; errorCode={$result->errorCode} msg={$result->errorMessage}",
        );
    }

    public function testIdempotentReplayReturnsExistingSavedDocId(): void
    {
        // extracted_document row exists with target_row_ndx + status=40
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('core_mail_extracted_documents'), 678)
            ->willReturn(new Row(['target_row_ndx' => 1234, 'status' => 40]));

        // Heads gateway must NEVER be called — idempotent fast-path
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->expects($this->never())->method('saveDocument');

        $applier = $this->buildApplier(db: $db, heads: $heads);

        $payload = $this->payloadWithCanCreateSupplier(['full_name' => 'X']);
        $payload['source']['extractedDoc'] = 678;
        $result = $applier->apply($payload);

        $this->assertTrue($result->success);
        $this->assertSame(1234, $result->savedId);
        $this->assertSame('alreadyApplied', $result->canonical['_resolve']['summary']['status']);
    }

    public function testIdempotentSkippedWhenStatusNotApplied(): void
    {
        // target_row_ndx already set but status still pending (20) — applier
        // must NOT short-circuit; it should proceed normally.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['target_row_ndx' => 1234, 'status' => 20]));

        $resolvers = $this->buildAutoCreateResolvers(['full_name' => 'X']);
        $applier = $this->buildApplier(
            db: $db, party: $resolvers['party'], item: $resolvers['item'], unit: $resolvers['unit'],
            vat: $resolvers['vat'], bank: $resolvers['bank'],
        );
        $payload = $this->payloadWithCanCreateSupplier(['full_name' => 'X']);
        $payload['source']['extractedDoc'] = 678;
        // No applyOptions → strict mode → expected to fail with unresolved_required
        $result = $applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    // ── transform(): import-mode virtual fields ─────────────────────────────

    /**
     * Invoke the private transform() with a minimal plan/sideIds via reflection.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function invokeTransform(DocumentApplier $applier, array $canonical): array
    {
        $plan = [
            'resolvedSupplier' => 5, 'resolvedCustomer' => null, 'resolvedSupplierBank' => null,
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [], 'resolvedRowVatCodes' => [],
        ];
        $sideIds = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => []];

        $ref = new \ReflectionMethod($applier, 'transform');
        return $ref->invoke($applier, $canonical, $plan, $sideIds, null);
    }

    public function testTransformPassesImportNumberAsVirtualField(): void
    {
        $applier = $this->buildApplier(); // db mock → resolveNumberSeries/Vat return null
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'applyOptions' => [
                'importNumber'        => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
                'importOwnBankAccount' => 17,
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(
            ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
            $data['_importNumber'],
        );
        $this->assertSame(17, $data['bank_account']);
    }

    public function testTransformPreservesExplicitNullSequenceNumber(): void
    {
        // Migrated duplicate keys: number outside the series formula travels
        // as sequenceNumber = null and must NOT be coerced to 0 (which would
        // trigger DocDocument's malformed-payload fallback).
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'applyOptions' => [
                'importNumber' => ['docNumber' => '2024-0042-2', 'sequenceNumber' => null],
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(
            ['docNumber' => '2024-0042-2', 'sequenceNumber' => null],
            $data['_importNumber'],
        );
    }

    public function testTransformCoercesMissingSequenceNumberToZero(): void
    {
        // Absent key (as opposed to explicit null) keeps the legacy behavior:
        // 0 → DocDocument falls back to normal number assignment.
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'applyOptions' => [
                'importNumber' => ['docNumber' => '2024-0042'],
            ],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(
            ['docNumber' => '2024-0042', 'sequenceNumber' => 0],
            $data['_importNumber'],
        );
    }

    public function testTransformOmitsImportFieldsWhenNotRequested(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
        ];

        $data = $this->invokeTransform($applier, $canonical);

        // array_filter drops null _importNumber and null bank_account.
        $this->assertArrayNotHasKey('_importNumber', $data);
        $this->assertArrayNotHasKey('bank_account', $data);
    }

    public function testTransformToleratesNullVat(): void
    {
        // Top-level `vat` je od schema fixes nullable — null se musí chovat
        // stejně jako chybějící objekt (defaulty fromBase/domestic).
        $applier = $this->buildApplier();
        $canonical = [
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2024-06-01'],
            'vat'       => null,
        ];

        $data = $this->invokeTransform($applier, $canonical);

        $this->assertSame(1, $data['vat_mode']);  // fromBase
        $this->assertSame(0, $data['vat_place']); // domestic
    }

    // ── transform(): derivace total_rounding_mode ────────────────────────────

    /**
     * @param array<string, mixed> $extra Merged over the minimal canonical.
     * @return array<string, mixed>
     */
    private function transformWithTotals(array $extra): array
    {
        $applier = $this->buildApplier();
        $canonical = array_merge([
            'docType'   => 'invoiceReceived',
            'selfParty' => 'customer',
            'dates'     => ['issueDate' => '2026-07-01'],
        ], $extra);

        return $this->invokeTransform($applier, $canonical);
    }

    public function testDeriveRoundingModeFromRecapFeinkost(): void
    {
        // Reálný scénář z alfy (extracted doc 42): recap 45.00 + 1664.05
        // = 1709.05, deklarováno 1709.00 → matematické zaokrouhlení (mode 1).
        $data = $this->transformWithTotals([
            'vatRecap' => [
                ['vatCode' => 'cz-120', 'vatPct' => 12, 'total' => 45.00],
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'total' => 1664.05],
            ],
            'totals' => [
                'totalBase' => 1522.95, 'totalVat' => 186.10,
                'totalAmount' => 1709.00, 'totalRounding' => -0.05,
            ],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeCeil(): void
    {
        // computed 1708.40, declared 1709.00 → round dá 1708, ceil sedí → 3.
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1708.40]],
            'totals'   => ['totalAmount' => 1709.00],
        ]);

        $this->assertSame(3, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeFloor(): void
    {
        // computed 1709.55, declared 1709.00 → round dá 1710, ceil 1710,
        // floor sedí → 4.
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.55]],
            'totals'   => ['totalAmount' => 1709.00],
        ]);

        $this->assertSame(4, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModePrefersMathOverCeil(): void
    {
        // computed X.50: round half-up i ceil dají týž výsledek — mode 1
        // je konvence (D4).
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.50]],
            'totals'   => ['totalAmount' => 1710.00],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeSkippedWhenDiffTooLarge(): void
    {
        // Rozdíl 1.95 není zaokrouhlení — mode se nenastaví, warning
        // z validátoru zůstává.
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.05]],
            'totals'   => ['totalAmount' => 1711.00],
        ]);

        $this->assertArrayNotHasKey('total_rounding_mode', $data);
    }

    public function testDeriveRoundingModeSkippedWhenWithinTolerance(): void
    {
        $data = $this->transformWithTotals([
            'vatRecap' => [['vatPct' => 21, 'total' => 1709.05]],
            'totals'   => ['totalAmount' => 1709.05],
        ]);

        $this->assertArrayNotHasKey('total_rounding_mode', $data);
    }

    public function testDeriveRoundingModeSkippedWithoutTotals(): void
    {
        $data = $this->transformWithTotals([]);

        $this->assertArrayNotHasKey('total_rounding_mode', $data);
    }

    public function testDeriveRoundingModeFallsBackToBasePlusVat(): void
    {
        // Bez recapu se computed bere z totalBase + totalVat.
        $data = $this->transformWithTotals([
            'totals' => [
                'totalBase' => 1522.95, 'totalVat' => 186.10,
                'totalAmount' => 1709.00,
            ],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeIncompleteRecapFallsBackToBasePlusVat(): void
    {
        // Recap s řádkem bez numeric total se nepoužije — nastupuje
        // totalBase + totalVat.
        $data = $this->transformWithTotals([
            'vatRecap' => [
                ['vatPct' => 12, 'total' => 45.00],
                ['vatPct' => 21], // total chybí
            ],
            'totals' => [
                'totalBase' => 1522.95, 'totalVat' => 186.10,
                'totalAmount' => 1709.00,
            ],
        ]);

        $this->assertSame(1, $data['total_rounding_mode']);
    }

    public function testDeriveRoundingModeFallsBackToRows(): void
    {
        // Bez recapu i totalBase/totalVat se computed sčítá z řádků
        // s DPH per řádek: 999.67 × 1.21 = 1209.60 → declared 1209.00
        // je floor → mode 4.
        $data = $this->transformWithTotals([
            'rows'   => [['totalPrice' => 999.67, 'vat' => ['pct' => 21]]],
            'totals' => ['totalAmount' => 1209.00],
        ]);

        $this->assertSame(4, $data['total_rounding_mode']);
    }

    // ── resolveNumberSeriesFor(): code selection + error path ───────────────

    private function invokeResolveSeries(DocumentApplier $applier, string $docType, ?string $seriesCode): ?int
    {
        $ref = new \ReflectionMethod($applier, 'resolveNumberSeriesFor');
        return $ref->invoke($applier, $docType, $seriesCode);
    }

    public function testResolveNumberSeriesByCodeMatches(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args;
            return new Row(['id' => 55]);
        });
        $applier = $this->buildApplier(db: $db);

        // kód 5 u invni → konkrétní řada (např. „Ostatní závazky")
        $this->assertSame(55, $this->invokeResolveSeries($applier, 'invni', '5'));
        // SQL filtruje podle doc_number_code a váže docType i kód
        $this->assertStringContainsString('doc_number_code', (string) $captured[0]);
        $this->assertContains('invni', $captured);
        $this->assertContains('5', $captured);
    }

    public function testResolveNumberSeriesByUnknownCodeThrows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null); // nic nematchuje
        $applier = $this->buildApplier(db: $db);

        $this->expectException(NumberSeriesNotFoundException::class);
        $this->invokeResolveSeries($applier, 'invni', '999');
    }

    public function testResolveNumberSeriesWithoutCodeFallsBackToFirstActive(): void
    {
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args;
            return new Row(['id' => 1]);
        });
        $applier = $this->buildApplier(db: $db);

        $this->assertSame(1, $this->invokeResolveSeries($applier, 'invni', null));
        // Stará cesta NESMÍ filtrovat podle doc_number_code (zpětná kompatibilita)
        $this->assertStringNotContainsString('doc_number_code', (string) $captured[0]);
    }

    // ── Účetní doklad (accountingDocument → cmnbkp) ─────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function invokeTransformWithPlan(DocumentApplier $applier, array $canonical, array $plan): array
    {
        $sideIds = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => []];
        $ref = new \ReflectionMethod($applier, 'transform');
        return $ref->invoke($applier, $canonical, $plan, $sideIds, null);
    }

    public function testValidateAcceptsAccountingDocumentWithoutParty(): void
    {
        $applier = $this->buildApplier();
        $result = $applier->validate([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'accountingDocument',
            'dates' => ['issueDate' => '2026-06-10'],
            'rows' => [
                ['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit', 'totalPrice' => 1000.0],
                ['operation' => 'acc.record', 'account' => '321100', 'accSide' => 'credit', 'totalPrice' => 1000.0],
            ],
        ]);
        // Žádný požadavek na partnera/selfParty — accountingDocument je
        // party-agnostický (DocumentValidator switch default).
        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? []));
    }

    public function testTransformAccountingDocumentRowsCarryContation(): void
    {
        $applier = $this->buildApplier(); // db mock → series/vat reg null
        $canonical = [
            'docType' => 'accountingDocument',
            'dates'   => ['issueDate' => '2026-06-10', 'accountingDate' => '2026-06-10'],
            'rows' => [
                ['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit',
                 'totalPrice' => 1000.0],
                ['operation' => 'acc.record', 'account' => '321100', 'accSide' => 'credit',
                 'totalPrice' => 1000.0, 'paymentReference' => 'VS123', 'dueDate' => '2026-07-10'],
            ],
        ];
        $plan = [
            'resolvedHeadPartner' => null,
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [], 'resolvedRowVatCodes' => [],
            'resolvedRowAccounts' => [0 => 195, 1 => 207],
            'resolvedRowPartners' => [1 => 42],
        ];

        $data = $this->invokeTransformWithPlan($applier, $canonical, $plan);

        $this->assertSame('cmnbkp', $data['doc_type']);
        $this->assertArrayNotHasKey('partner', $data); // hlavička bez partnera → null → array_filter

        $rows = $data['rows'];
        $this->assertCount(2, $rows);

        // MD řádek: účet 195, strana 0, částka přímo (price_calc_mode 1).
        $this->assertSame(195, $rows[0]['account']);
        $this->assertSame(0, $rows[0]['acc_side']);
        $this->assertSame(1000.0, $rows[0]['total_price']);
        $this->assertSame(1, $rows[0]['price_calc_mode']);
        $this->assertArrayNotHasKey('partner', $rows[0]);

        // DAL řádek: účet 207, strana 1, per-řádkový partner + VS + splatnost.
        $this->assertSame(207, $rows[1]['account']);
        $this->assertSame(1, $rows[1]['acc_side']);
        $this->assertSame(42, $rows[1]['partner']);
        $this->assertSame('VS123', $rows[1]['payment_reference']);
        $this->assertSame('2026-07-10', $rows[1]['due_date']);
    }

    public function testTransformAccountingDocumentUsesHeadPartnerPin(): void
    {
        $applier = $this->buildApplier();
        $canonical = [
            'docType' => 'accountingDocument',
            'dates'   => ['issueDate' => '2026-06-10'],
            'rows'    => [['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit', 'totalPrice' => 50.0]],
        ];
        $plan = [
            'resolvedHeadPartner' => 77,
            'rowSkips' => [], 'resolvedRowItems' => [], 'resolvedRowUnits' => [], 'resolvedRowVatCodes' => [],
            'resolvedRowAccounts' => [0 => 195], 'resolvedRowPartners' => [],
        ];

        $data = $this->invokeTransformWithPlan($applier, $canonical, $plan);
        $this->assertSame(77, $data['partner']);
    }

    public function testMapDocTypeValueTranslatesAliasAndPassesThrough(): void
    {
        $this->assertSame('invni', DocumentApplier::mapDocTypeValue('invoiceReceived'));
        $this->assertSame('invno', DocumentApplier::mapDocTypeValue('invoiceIssued'));
        $this->assertSame('cmnbkp', DocumentApplier::mapDocTypeValue('accountingDocument'));
        $this->assertSame('invni', DocumentApplier::mapDocTypeValue('invni'));
        $this->assertSame('xyz', DocumentApplier::mapDocTypeValue('xyz'));
    }

    public function testPreviewCarriesOverRowEnrichment(): void
    {
        // withResolve staví _resolve z fresh resolvu — enrichment audit
        // (RowHistoryEnricher) z příchozího canonical musí přežít per index.
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $itemResolver = $this->createMock(ItemResolver::class);
        $itemResolver->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'highEU', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $applier = $this->buildApplier(party: $party, item: $itemResolver, unit: $unit, vat: $vat, bank: $bank);

        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $enrichment = [
            'matchedBy'   => 'historyExactNorm',
            'confidence'  => 'high',
            'sourceDocId' => 1001,
            'suggested'   => ['ourCode' => 'NET500'],
        ];
        $payload['_resolve'] = ['rows' => [['index' => 0, 'enrichment' => $enrichment]]];

        $result = $applier->preview($payload);

        $this->assertTrue($result->success);
        $rowResolve = $result->canonical['_resolve']['rows'][0];
        $this->assertSame(0, $rowResolve['index']);
        $this->assertSame($enrichment, $rowResolve['enrichment']);
        // Fresh resolve zůstává nedotčený vedle přeneseného auditu.
        $this->assertSame('matched', $rowResolve['item']['status']);
    }
}
