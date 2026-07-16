<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\RegistryController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\ExtractedTextFiller;
use Shipard\Module\Base\Registry\FileFromMessageService;
use Shipard\Module\Base\Registry\RegistryImportService;

/**
 * Unit testy extract-text akce a HTTP slupky importu (fromMessage kryje
 * integrační RegistryFromMessageTest, jádro importu integrační
 * RegistryImportEndpointTest — HTTP slupka je tenká a stejná).
 */
class RegistryControllerTest extends TestCase
{
    private function controller(
        ?DataSourceConnection $db = null,
        ?ExtractedTextFiller $filler = null,
        ?RegistryImportService $importer = null,
    ): RegistryController {
        return new RegistryController(
            $this->createMock(FileFromMessageService::class),
            $filler ?? $this->createMock(ExtractedTextFiller::class),
            $db ?? $this->createMock(DataSourceConnection::class),
            $importer,
        );
    }

    private function jsonRequest(array $body): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        return Request::fromArray('POST', '/_registry/import', [], (string) json_encode($body), $server);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    public function testExtractTextRequiresAuth(): void
    {
        $response = $this->controller()->extractText(AuthContext::anonymous(), 5);
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testExtractTextReturns404ForMissingDocument(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $response = $this->controller($db)->extractText(new AuthContext(true, 1), 999);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testExtractTextReturns404ForTrashedDocument(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'docState' => 90]);

        $response = $this->controller($db)->extractText(new AuthContext(true, 1), 5);

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testExtractTextRegeneratesAndReturnsStats(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'docState' => 40]);

        $filler = $this->createMock(ExtractedTextFiller::class);
        $filler->expects($this->once())
            ->method('fill')
            ->with(5, true)
            ->willReturn(['chars' => 1234, 'attachments' => 2]);

        $response = $this->controller($db, $filler)->extractText(new AuthContext(true, 1), 5);

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame(['chars' => 1234, 'attachments' => 2], $response->getPayload()['data']);
    }

    // -------------------------------------------------------------------------
    // POST /_registry/import — import()
    // -------------------------------------------------------------------------

    public function testRegistryImportAnonymousReturns401(): void
    {
        $response = $this->controller()->import(AuthContext::anonymous(), $this->jsonRequest(['title' => 'x']));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testRegistryImportSessionTokenReturns401(): void
    {
        $auth = new AuthContext(true, 1, 'session', 'shpd_st_xxx');
        $response = $this->controller()->import($auth, $this->jsonRequest(['title' => 'x']));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testRegistryImportEmptyBodyReturns400(): void
    {
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');
        $importer = $this->createMock(RegistryImportService::class);
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        $request = Request::fromArray('POST', '/_registry/import', [], 'not-json', $server);

        $response = $this->controller(importer: $importer)->import($auth, $request);

        $this->assertSame(400, $this->statusOf($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }

    public function testRegistryImportMapsServiceErrorWithDetails(): void
    {
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');
        $importer = $this->createMock(RegistryImportService::class);
        $importer->method('import')->willReturn([
            'ok' => false,
            'errorCode' => 'VALIDATION_ERROR',
            'errorMessage' => 'docKind is required',
            'statusCode' => 422,
            'details' => [['field' => 'docKind', 'code' => 'required']],
        ]);

        $response = $this->controller(importer: $importer)->import($auth, $this->jsonRequest([]));

        $this->assertSame(422, $this->statusOf($response));
        $error = $response->getPayload()['error'];
        $this->assertSame('VALIDATION_ERROR', $error['code']);
        $this->assertSame('docKind', $error['details'][0]['field']);
    }

    public function testRegistryImportMapsCreatedAndDedupeResults(): void
    {
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $importer = $this->createMock(RegistryImportService::class);
        $importer->method('import')->willReturn([
            'ok' => true, 'id' => 7, 'existed' => false, 'statusCode' => 201, 'warning' => 'BINDER_NOT_FOUND',
        ]);
        $response = $this->controller(importer: $importer)->import($auth, $this->jsonRequest(['title' => 'x']));
        $this->assertSame(201, $this->statusOf($response));
        $this->assertSame(['id' => 7, 'warning' => 'BINDER_NOT_FOUND'], $response->getPayload()['data']);

        $importer = $this->createMock(RegistryImportService::class);
        $importer->method('import')->willReturn(['ok' => true, 'id' => 7, 'existed' => true, 'statusCode' => 200]);
        $response = $this->controller(importer: $importer)->import($auth, $this->jsonRequest(['title' => 'x']));
        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame(['id' => 7, 'existed' => true], $response->getPayload()['data']);
    }
}
