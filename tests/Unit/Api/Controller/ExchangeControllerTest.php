<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\ExchangeController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Module\Core\Exchange\Document\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

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
            ApplyResult::ok(['savedDocId' => 123], savedDocId: 123),
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
}
