<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ChatController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Unit testy pro ChatController (Fáze 1 — CRUD konverzací).
 *
 * DB mockujeme; kontrolujeme auth gate, user-scoping (vlastní vs cizí
 * konverzace), soft-delete (docState=90) a že na zprávy není zápisový
 * endpoint (testuje se nepřímo — controller žádnou takovou metodu nemá).
 * Plná SQL cesta proti reálné DB je mimo rozsah unit testů.
 */
class ChatControllerTest extends TestCase
{
    private const USER_ID = 100;

    private function request(string $method, string $path, array $body = [], array $query = []): Request
    {
        $rawBody = $body === [] ? '' : (string) json_encode($body);
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        return Request::fromArray($method, $path, $query, $rawBody, $server);
    }

    private function auth(): AuthContext
    {
        return new AuthContext(true, self::USER_ID, 'session', 'shpd_st_xxx');
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    // -------------------------------------------------------------------
    // Auth gate — každý endpoint odmítne neauthnutého (401)
    // -------------------------------------------------------------------

    public function testListRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->list(AuthContext::anonymous(), $this->request('GET', '/_chat/conversations'));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testCreateRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->create(AuthContext::anonymous(), $this->request('POST', '/_chat/conversations'));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testShowRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->show(AuthContext::anonymous(), 1);
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testRenameRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->rename(AuthContext::anonymous(), 1, $this->request('PATCH', '/_chat/conversations/1', ['title' => 'x']));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testDeleteRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->delete(AuthContext::anonymous(), 1);
        $this->assertSame(401, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // create
    // -------------------------------------------------------------------

    public function testCreateInsertsOwnedConversation(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())
            ->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = ['table' => $table, 'data' => $data];
                return 42;
            });

        $ctrl = new ChatController($db);
        $response = $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['title' => '  Hello  ']));

        $this->assertSame(201, $this->statusOf($response));
        $this->assertSame(42, $response->getPayload()['data']['id']);

        $this->assertSame('core_chat_conversations', $captured['table']);
        $this->assertSame(self::USER_ID, $captured['data']['user']);
        $this->assertSame(self::USER_ID, $captured['data']['created_by']);
        $this->assertSame('Hello', $captured['data']['title']);
        $this->assertSame(10, $captured['data']['docState']);
    }

    public function testCreateBlankTitleStoredAsNull(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });

        $ctrl = new ChatController($db);
        $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['title' => '   ']));

        $this->assertNull($captured['title']);
    }

    // -------------------------------------------------------------------
    // list — jen vlastní a nesmazané
    // -------------------------------------------------------------------

    public function testListScopesToUserAndExcludesDeleted(): void
    {
        $args = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$a) use (&$args): array {
            $args = $a;
            return [
                ['id' => 1, 'title' => 'A', 'backend' => null, 'model_snapshot' => null, 'tokens_input' => 0, 'tokens_output' => 0, 'cost' => 0, 'created' => '2026-06-01 10:00:00', 'modified' => '2026-06-02 10:00:00'],
            ];
        });

        $ctrl = new ChatController($db);
        $response = $ctrl->list($this->auth(), $this->request('GET', '/_chat/conversations'));

        $this->assertSame(200, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertCount(1, $payload['data']);
        $this->assertSame(1, $payload['data'][0]['id']);

        // SQL musí scopovat na uživatele a vyloučit smazané (docState=90).
        $sql = (string) $args[0];
        $this->assertStringContainsString('`user` = %i', $sql);
        $this->assertStringContainsString('`docState` <> %i', $sql);
        $this->assertContains(self::USER_ID, $args);
        $this->assertContains(90, $args);
    }

    // -------------------------------------------------------------------
    // show — cizí/neexistující → 404
    // -------------------------------------------------------------------

    public function testShowForeignConversationReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // not owned / not found

        $ctrl = new ChatController($db);
        $response = $ctrl->show($this->auth(), 999);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testShowReturnsConversationWithMessages(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 5, 'user' => self::USER_ID, 'title' => 'Chat', 'backend' => null,
            'model_snapshot' => null, 'tokens_input' => 0, 'tokens_output' => 0,
            'cost' => 0, 'created' => '2026-06-01 10:00:00', 'modified' => '2026-06-01 10:00:00',
            'docState' => 10,
        ]);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'seq' => 0, 'role' => 'user', 'kind' => 'user_text', 'content' => '[{"type":"text","text":"hi"}]', 'tokens_input' => null, 'tokens_output' => null, 'cost' => null, 'model_name' => null, 'created' => '2026-06-01 10:00:00'],
        ]);

        $ctrl = new ChatController($db);
        $response = $ctrl->show($this->auth(), 5);

        $this->assertSame(200, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertSame(5, $payload['data']['conversation']['id']);
        $this->assertCount(1, $payload['data']['messages']);
        // content se vrací jako dekódované pole bloků, ne řetězec
        $this->assertSame([['type' => 'text', 'text' => 'hi']], $payload['data']['messages'][0]['content']);
    }

    // -------------------------------------------------------------------
    // rename
    // -------------------------------------------------------------------

    public function testRenameUpdatesTitle(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'docState' => 10]);
        $db->method('updateWhere')->willReturnCallback(function (string $table, array $data) use (&$captured): void {
            $captured = $data;
        });

        $ctrl = new ChatController($db);
        $response = $ctrl->rename($this->auth(), 5, $this->request('PATCH', '/_chat/conversations/5', ['title' => 'Renamed']));

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame('Renamed', $captured['title']);
    }

    public function testRenameMissingTitleReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'docState' => 10]);

        $ctrl = new ChatController($db);
        $response = $ctrl->rename($this->auth(), 5, $this->request('PATCH', '/_chat/conversations/5', []));

        $this->assertSame(422, $this->statusOf($response));
    }

    public function testRenameForeignReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $ctrl = new ChatController($db);
        $response = $ctrl->rename($this->auth(), 5, $this->request('PATCH', '/_chat/conversations/5', ['title' => 'x']));

        $this->assertSame(404, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // delete — soft-delete (docState=90), ne fyzické smazání
    // -------------------------------------------------------------------

    public function testDeleteSoftDeletesViaDocState(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'docState' => 10]);
        $db->expects($this->never())->method('deleteWhere');
        $db->method('updateWhere')->willReturnCallback(function (string $table, array $data) use (&$captured): void {
            $captured = $data;
        });

        $ctrl = new ChatController($db);
        $response = $ctrl->delete($this->auth(), 5);

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame(90, $captured['docState']);
    }

    public function testDeleteForeignReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $ctrl = new ChatController($db);
        $response = $ctrl->delete($this->auth(), 5);

        $this->assertSame(404, $this->statusOf($response));
    }
}
