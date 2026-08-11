<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Api\Request;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;

/**
 * Unit testy pro auth-gate a validační větve MailControlleru.
 * Happy path (multipart upload) pokrývají integrační testy.
 */
class MailControllerTest extends TestCase
{
    private function request(array $headers = []): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::fromArray('POST', '/_mail/incoming', [], '', $server);
    }

    private function controller(DataSourceConnection $db): MailController
    {
        return new MailController($db, '/tmp/shpd_mail_test', [], new DocumentRegistry());
    }

    public function testAnonymousRequestReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->receiveIncoming(AuthContext::anonymous(), $this->request());

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('UNAUTHORIZED', $response->getPayload()['error']['code']);
    }

    public function testSessionTokenIsRejected(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_xxx');
        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testWrongUserReturns403(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'admin']);

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 5, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(403, $this->statusOf($response));
        $this->assertSame('FORBIDDEN', $response->getPayload()['error']['code']);
    }

    public function testIdempotencyReplayReturnsCachedResponse(): void
    {
        $cachedPayload = json_encode([
            'success' => true,
            'data' => [
                'ndx' => 123,
                'message_id' => 'MSG-20260418-0001',
                'idempotent_replay' => false,
            ],
        ]);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],            // auth user lookup
            ['message' => 123, 'response_body' => $cachedPayload],  // idempotency lookup
        );

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');
        $request = $this->request(['X-Idempotency-Key' => 'abc123']);

        $response = $ctrl->receiveIncoming($auth, $request);

        $this->assertSame(201, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['idempotent_replay']);
        $this->assertSame(123, $payload['data']['ndx']);
        $this->assertSame('MSG-20260418-0001', $payload['data']['message_id']);
    }

    public function testMissingReceivedAtReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_mail_router']);

        // Empty $_POST → validation fails on received_at
        $_POST = [];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
        $this->assertStringContainsString('received_at', $response->getPayload()['error']['message']);
    }

    public function testInvalidSenderEmailReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_mail_router']);

        $_POST = [
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'not-an-email',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertSame('sender_email', $payload['error']['details'][0]['field']);
    }

    public function testUnknownMailboxReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],  // auth
            null,                         // mailbox lookup miss
        );

        $_POST = [
            'mailbox' => 'unknown',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertStringContainsString("'unknown'", $response->getPayload()['error']['message']);
    }

    public function testEmptyMailboxWithNoDefaultReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],  // auth
            null,                         // default mailbox lookup miss
        );

        $_POST = [
            'mailbox' => '',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertStringContainsString('no default mailbox', $response->getPayload()['error']['message']);
    }

    public function testMissingRawSourceReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],     // auth
            ['id' => 5],                     // default mailbox
        );

        $_POST = [
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('raw_source', $response->getPayload()['error']['details'][0]['field']);
    }

    // -------------------------------------------------------------------------
    // POST /_mail/import — importMessage()
    // -------------------------------------------------------------------------

    private function jsonRequest(array $body): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        return Request::fromArray('POST', '/_mail/import', [], (string) json_encode($body), $server);
    }

    public function testImportAnonymousReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->importMessage(AuthContext::anonymous(), $this->jsonRequest(['subject' => 'x']));

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('UNAUTHORIZED', $response->getPayload()['error']['code']);
    }

    public function testImportSessionTokenReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_xxx');
        $response = $ctrl->importMessage($auth, $this->jsonRequest(['subject' => 'x']));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testImportAcceptsAnyApiKeyUser(): void
    {
        // Na rozdíl od /_mail/incoming není import omezen na _mail_router:
        // libovolný api_key projde gate a spadne až na mailbox resolve.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // default mailbox miss → 422, ne 403

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 99, 'api_key', 'shpd_ak_importer');

        $response = $ctrl->importMessage($auth, $this->jsonRequest(['sender_email' => 'a@b.cz']));

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testImportEmptyBodyReturns400(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');
        $request = Request::fromArray('POST', '/_mail/import', [], '', ['HTTP_HOST' => 'test']);

        $response = $ctrl->importMessage($auth, $request);

        $this->assertSame(400, $this->statusOf($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }

    public function testImportUnknownMailboxReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // mailbox lookup miss

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->importMessage($auth, $this->jsonRequest([
            'mailbox' => 'nonexistent',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ]));

        $this->assertSame(422, $this->statusOf($response));
        $this->assertStringContainsString("'nonexistent'", $response->getPayload()['error']['message']);
    }

    // Pozn.: validace povinných polí / formátu sender_email žije v
    // IncomingMessageDocument, ne v controlleru (na rozdíl od /_mail/incoming).
    // Unit úroveň s prázdným DocumentRegistry ji nezachytí — pokrývá ji
    // MailImportEndpointTest::testImportInvalidSenderEmailReturns422.

    // ── ISDOC import hook (tasks/mail-isdoc-import.md) ──────────────────────
    //
    // Plný multipart flow pokrývají integrační testy; tady se testuje jen
    // lazy gate — factory se volá právě tehdy, když je mezi přílohami
    // kandidát. Orchestrace samotného importu: IsdocImportServiceTest.

    private function invokeRunIsdocImport(MailController $ctrl, array $attachments): void
    {
        $ref = new \ReflectionClass($ctrl);
        $ref->getMethod('runIsdocImport')->invoke($ctrl, 42, $attachments);
    }

    public function testIsdocImportFactoryInvokedForCandidateAttachment(): void
    {
        $factoryCalls = 0;
        $service = $this->createMock(\Shipard\Module\Core\Mail\IsdocImportService::class);
        $service->expects($this->once())->method('tryImport')->with(42);

        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = new MailController(
            $db, '/tmp/shpd_mail_test', [], new DocumentRegistry(), null, null,
            function () use (&$factoryCalls, $service) {
                $factoryCalls++;
                return $service;
            },
        );

        $this->invokeRunIsdocImport($ctrl, [
            ['id' => 1, 'name' => 'faktura.pdf', 'mime_type' => 'application/pdf'],
            ['id' => 2, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml'],
        ]);

        $this->assertSame(1, $factoryCalls);
    }

    public function testIsdocImportFactoryNotInvokedWithoutCandidate(): void
    {
        // PDF je kandidát vždy (nosič embedded ISDOC, PDF/A-3) — bez kandidáta
        // znamená jen přílohy mimo ISDOC/XML/PDF (obrázky apod.).
        $factoryCalls = 0;
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = new MailController(
            $db, '/tmp/shpd_mail_test', [], new DocumentRegistry(), null, null,
            function () use (&$factoryCalls) {
                $factoryCalls++;
                return $this->createMock(\Shipard\Module\Core\Mail\IsdocImportService::class);
            },
        );

        $this->invokeRunIsdocImport($ctrl, [
            ['id' => 1, 'name' => 'scan.jpg', 'mime_type' => 'image/jpeg'],
        ]);

        $this->assertSame(0, $factoryCalls);
    }

    public function testIsdocImportSwallowsFactoryFailure(): void
    {
        // Import nikdy nesmí shodit příjem pošty — i výbuch wiringu se polkne.
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = new MailController(
            $db, '/tmp/shpd_mail_test', [], new DocumentRegistry(), null, null,
            static fn() => throw new \RuntimeException('wiring failed'),
        );

        $this->invokeRunIsdocImport($ctrl, [
            ['id' => 2, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml'],
        ]);

        $this->addToAssertionCount(1); // žádná výjimka nepropadla
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];
    }

    private function statusOf(\Shipard\Api\Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }
}
