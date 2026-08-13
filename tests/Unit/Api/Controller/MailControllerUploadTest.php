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
 * Unit testy auth-gate a validačních větví `POST /_mail/messages/upload`
 * (tasks/mail-dashboard-upload.md). Happy path s reálnou DB a file storage
 * pokrývá integrační MailUploadEndpointTest.
 */
class MailControllerUploadTest extends TestCase
{
    private function request(): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        return Request::fromArray('POST', '/_mail/messages/upload', [], '', $server);
    }

    private function controller(DataSourceConnection $db): MailController
    {
        return new MailController($db, '/tmp/shpd_mail_upload_test', [], new DocumentRegistry());
    }

    /** Array-form $_FILES['attachments'] s N soubory. */
    private function fakeAttachments(int $count): array
    {
        $range = range(1, $count);
        return [
            'name' => array_map(static fn(int $i): string => "file{$i}.pdf", $range),
            'tmp_name' => array_fill(0, $count, '/tmp/nonexistent'),
            'error' => array_fill(0, $count, UPLOAD_ERR_OK),
            'size' => array_fill(0, $count, 10),
            'type' => array_fill(0, $count, 'application/pdf'),
        ];
    }

    public function testAnonymousReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->uploadMessages(AuthContext::anonymous(), $this->request());

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('UNAUTHORIZED', $response->getPayload()['error']['code']);
    }

    public function testMailRouterAccountReturns403(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_mail_router', 'email' => null, 'full_name' => 'Router']);

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->uploadMessages($auth, $this->request());

        $this->assertSame(403, $this->statusOf($response));
        $this->assertSame('FORBIDDEN', $response->getPayload()['error']['code']);
    }

    public function testAiAnalyzerAccountReturns403(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer', 'email' => null, 'full_name' => 'Analyzer']);

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 3, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->uploadMessages($auth, $this->request());

        $this->assertSame(403, $this->statusOf($response));
    }

    public function testSessionTokenPassesAuthGate(): void
    {
        // Session token je pro upload OK (na rozdíl od /_mail/incoming) —
        // běžný uživatel projde gate a spadne až na validaci mode.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'user', 'email' => 'u@example.com', 'full_name' => 'User']);

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 5, 'session', 'shpd_st_xxx');

        $response = $ctrl->uploadMessages($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('mode', $response->getPayload()['error']['details'][0]['field']);
    }

    public function testInvalidModeReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'user', 'email' => 'u@example.com', 'full_name' => 'User']);

        $_POST = ['mode' => 'both'];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 5, 'session', 'shpd_st_xxx');

        $response = $ctrl->uploadMessages($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testMissingFilesReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'user', 'email' => 'u@example.com', 'full_name' => 'User']);

        $_POST = ['mode' => 'perFile'];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 5, 'session', 'shpd_st_xxx');

        $response = $ctrl->uploadMessages($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('attachments', $response->getPayload()['error']['details'][0]['field']);
    }

    public function testTooManyFilesReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'user', 'email' => 'u@example.com', 'full_name' => 'User']);

        $_POST = ['mode' => 'perFile'];
        $_FILES = ['attachments' => $this->fakeAttachments(21)];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 5, 'session', 'shpd_st_xxx');

        $response = $ctrl->uploadMessages($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('TOO_MANY_FILES', $response->getPayload()['error']['code']);
    }

    public function testNoDefaultMailboxReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => 'user', 'email' => 'u@example.com', 'full_name' => 'User'],  // user lookup
            null,                                                                    // default mailbox miss
        );

        $_POST = ['mode' => 'perFile'];
        $_FILES = ['attachments' => $this->fakeAttachments(1)];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 5, 'session', 'shpd_st_xxx');

        $response = $ctrl->uploadMessages($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertStringContainsString('no default mailbox', $response->getPayload()['error']['message']);
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
