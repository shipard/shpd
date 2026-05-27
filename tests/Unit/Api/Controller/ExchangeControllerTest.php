<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\ExchangeController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;

class ExchangeControllerTest extends TestCase
{
    private function buildRequest(string $method, string $path, ?array $body = null): Request
    {
        return Request::fromArray(
            $method,
            $path,
            [],
            $body !== null ? (string) json_encode($body) : '',
            ['HTTP_HOST' => 'localhost'],
        );
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return $prop->getValue($response);
    }

    public function testValidateRejectsMissingJsonBody(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('validate');
        $controller = new ExchangeController($applier);

        $response = $controller->validate($this->buildRequest('POST', '/api/v1/_exchange/docs/document/validate'));

        $this->assertSame(400, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('schema_invalid', $payload['error']['code']);
    }

    public function testValidateDelegatesToApplierAndReturnsSuccess(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('validate')
            ->willReturn(ApplyResult::ok(['format' => 'shpd.docs.document', 'enriched' => true]));
        $controller = new ExchangeController($applier);

        $response = $controller->validate($this->buildRequest('POST', '/api/v1/_exchange/docs/document/validate', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['canonical']['enriched']);
    }

    public function testValidatePropagatesErrorStatus(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('validate')->willReturn(
            ApplyResult::error('validation_failed', 'Validace selhala', ['_resolve' => ['issues' => []]], statusCode: 422),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->validate($this->buildRequest('POST', '/api/v1/_exchange/docs/document/validate', [
            'docType' => 'invoiceReceived',
        ]));

        $this->assertSame(422, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertSame('validation_failed', $payload['error']['code']);
        $this->assertArrayHasKey('details', $payload['error']);
    }

    public function testPreviewDelegatesToApplier(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('preview')
            ->willReturn(ApplyResult::ok(['_resolve' => ['summary' => ['status' => 'ok']]]));
        $controller = new ExchangeController($applier);

        $response = $controller->preview($this->buildRequest('POST', '/api/v1/_exchange/docs/document/preview', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(200, $this->getStatus($response));
    }

    public function testApplyReturnsSavedDocId(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::ok(['savedDocId' => 123], savedId: 123),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->apply($this->buildRequest('POST', '/api/v1/_exchange/docs/document/apply', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertSame(123, $payload['data']['savedDocId']);
    }

    public function testApplyConflictReturns409(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('conflict', 'Target gone', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->apply($this->buildRequest('POST', '/api/v1/_exchange/docs/document/apply', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('conflict', $response->getPayload()['error']['code']);
    }

    public function testApplyUnresolvedReturns422(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('unresolved_required', 'Doplň userAction', ['_resolve' => []], statusCode: 422),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->apply($this->buildRequest('POST', '/api/v1/_exchange/docs/document/apply', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(422, $this->getStatus($response));
        $this->assertSame('unresolved_required', $response->getPayload()['error']['code']);
    }

    // ── Person flow ────────────────────────────────────────────────────

    public function testValidatePersonReturns500WhenPersonApplierUnconfigured(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        // No PersonApplier injected — call must fail with 500.
        $controller = new ExchangeController($applier);

        $response = $controller->validatePerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/validate', ['format' => 'shpd.persons.person'],
        ));

        $this->assertSame(500, $this->getStatus($response));
        $this->assertSame('INTERNAL_ERROR', $response->getPayload()['error']['code']);
    }

    public function testValidatePersonDelegatesToPersonApplier(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $docApplier->expects($this->never())->method('validate');

        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->expects($this->once())
            ->method('validate')
            ->willReturn(ApplyResult::ok(['format' => 'shpd.persons.person', 'enriched' => true]));

        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->validatePerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/validate',
            ['format' => 'shpd.persons.person', 'personType' => 'company'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('canonical', $payload['data']);
    }

    public function testApplyPersonResponseUsesSavedPersonIdKey(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->method('apply')->willReturn(
            ApplyResult::ok(['savedPersonId' => 42], savedId: 42),
        );
        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->applyPerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/apply',
            ['format' => 'shpd.persons.person', 'personType' => 'company', 'country' => 'cz'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(42, $data['savedPersonId']);
        $this->assertArrayNotHasKey('savedDocId', $data, 'Person flow must not leak doc-flow key name');
    }

    public function testApplyPersonExistsReturns409(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->method('apply')->willReturn(
            ApplyResult::error('person_exists', 'Osoba existuje', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->applyPerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/apply',
            ['format' => 'shpd.persons.person'],
        ));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('person_exists', $response->getPayload()['error']['code']);
    }

    public function testApplyPersonRejectsMissingJsonBody(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->expects($this->never())->method('apply');
        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->applyPerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/apply',
        ));

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('schema_invalid', $response->getPayload()['error']['code']);
    }

    // ── Item flow ──────────────────────────────────────────────────────

    public function testValidateItemReturns500WhenItemApplierUnconfigured(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        // No ItemApplier injected — call must fail with 500.
        $controller = new ExchangeController($docApplier);

        $response = $controller->validateItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/validate', ['format' => 'shpd.items.item'],
        ));

        $this->assertSame(500, $this->getStatus($response));
        $this->assertSame('INTERNAL_ERROR', $response->getPayload()['error']['code']);
    }

    public function testValidateItemDelegatesToItemApplier(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $docApplier->expects($this->never())->method('validate');

        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->expects($this->once())
            ->method('validate')
            ->willReturn(ApplyResult::ok(['format' => 'shpd.items.item', 'enriched' => true]));

        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->validateItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/validate',
            ['format' => 'shpd.items.item', 'name' => 'Konzultace IT', 'unit' => 'h'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('canonical', $payload['data']);
    }

    public function testPreviewItemDelegatesToItemApplier(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->expects($this->once())
            ->method('preview')
            ->willReturn(ApplyResult::ok(['_resolve' => ['summary' => ['status' => 'ok']]]));
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->previewItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/preview',
            ['format' => 'shpd.items.item', 'name' => 'X', 'unit' => 'h'],
        ));

        $this->assertSame(200, $this->getStatus($response));
    }

    public function testApplyItemResponseUsesSavedItemIdKey(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->method('apply')->willReturn(
            ApplyResult::ok(['savedItemId' => 42], savedId: 42),
        );
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
            ['format' => 'shpd.items.item', 'name' => 'Konzultace IT', 'unit' => 'h'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(42, $data['savedItemId']);
        $this->assertArrayNotHasKey('savedDocId', $data, 'Item flow must not leak doc-flow key name');
        $this->assertArrayNotHasKey('savedPersonId', $data, 'Item flow must not leak person-flow key name');
    }

    public function testApplyItemItemExistsReturns409(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->method('apply')->willReturn(
            ApplyResult::error('item_exists', 'Položka existuje', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
            ['format' => 'shpd.items.item'],
        ));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('item_exists', $response->getPayload()['error']['code']);
    }

    public function testApplyItemCodeConflictReturns409(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->method('apply')->willReturn(
            ApplyResult::error('code_conflict', 'Kód kolikuje', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
            ['format' => 'shpd.items.item'],
        ));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('code_conflict', $response->getPayload()['error']['code']);
    }

    public function testApplyItemRejectsMissingJsonBody(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->expects($this->never())->method('apply');
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
        ));

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('schema_invalid', $response->getPayload()['error']['code']);
    }
}
