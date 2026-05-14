<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Document\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

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

        return new DocumentApplier(
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
}
