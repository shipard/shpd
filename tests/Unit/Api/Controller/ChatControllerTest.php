<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ChatController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
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

    // -------------------------------------------------------------------
    // sendMessage — streamed chat turn (Fáze 2a), fake LlmClient
    // -------------------------------------------------------------------

    private const BACKEND = [
        'id' => 1, 'provider' => 'anthropic', 'model' => 'claude-test',
        'api_key' => null, 'base_url' => null, 'max_tokens' => 4096,
    ];

    /**
     * DB mock for the streaming path. fetchRow returns the owned conversation
     * for the conversations query and $backend for the backends query;
     * insertRow appends to $inserts.
     *
     * @param array<int, array<string, mixed>> $inserts
     * @param array<string, mixed>|null        $backend
     */
    private function streamDb(array &$inserts, ?array $backend): DataSourceConnection
    {
        $conv = ['id' => 5, 'user' => self::USER_ID, 'backend' => null, 'docState' => 10];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            fn ($sql, ...$a) => str_contains((string) $sql, 'core_ai_backends') ? $backend : $conv,
        );
        $db->method('fetchSingle')->willReturn(null);
        $db->method('fetchAll')->willReturn([
            ['role' => 'user', 'content' => '[{"type":"text","text":"Ahoj"}]'],
        ]);
        $db->method('insertRow')->willReturnCallback(function ($table, array $data) use (&$inserts): int {
            $inserts[] = $data;
            return count($inserts);
        });
        return $db;
    }

    private function runProducer(Response $response): string
    {
        $ref = new \ReflectionClass($response);
        $producer = $ref->getProperty('streamProducer')->getValue($response);
        $this->assertIsCallable($producer);

        ob_start();
        try {
            $producer();
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }

    public function testSendMessageStreamsAndPersistsBothMessages(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['Ahoj', ', světe']));

        $response = $ctrl->sendMessage(
            $this->auth(),
            5,
            $this->request('POST', '/_chat/conversations/5/messages', ['text' => 'Ahoj']),
        );
        $this->assertSame(200, $this->statusOf($response));

        $out = $this->runProducer($response);
        $this->assertStringContainsString('event: text-delta', $out);
        $this->assertStringContainsString('event: message-complete', $out);

        // user message persisted before the stream, assistant after completion
        $this->assertCount(2, $inserts);
        $this->assertSame('user', $inserts[0]['role']);
        $this->assertSame('user_text', $inserts[0]['kind']);
        $this->assertSame('assistant', $inserts[1]['role']);
        $this->assertSame('assistant', $inserts[1]['kind']);
        $assistant = json_decode((string) $inserts[1]['content'], true);
        $this->assertSame('Ahoj, světe', $assistant[0]['text']);
        $this->assertSame(7, $inserts[1]['tokens_output']);
    }

    public function testSendMessageEmitsErrorEventAndKeepsUserMessage(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        // Throws before emitting any delta → no partial assistant text.
        $fake = new FakeLlmClient([], new LlmApiException(500, 'api_error', 'boom'));
        $ctrl = new ChatController($db, null, null, $fake);

        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));
        $out = $this->runProducer($response);

        $this->assertStringContainsString('event: error', $out);
        $this->assertStringContainsString('LLM_ERROR', $out);
        // user message survives; no assistant message written
        $this->assertCount(1, $inserts);
        $this->assertSame('user', $inserts[0]['role']);
    }

    public function testSendMessageWithoutLlmReturns503(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'backend' => null, 'docState' => 10]);

        $ctrl = new ChatController($db); // llm = null
        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'hi']));

        $this->assertSame(503, $this->statusOf($response));
    }

    public function testSendMessageNoActiveBackendReturns503(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, null); // backends query → null
        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['x']));

        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));

        $this->assertSame(503, $this->statusOf($response));
        $this->assertCount(1, $inserts); // user message persisted before backend resolution
    }

    public function testSendMessageForeignReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['x']));
        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testSendMessageRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class), null, null, new FakeLlmClient(['x']));
        $response = $ctrl->sendMessage(AuthContext::anonymous(), 5, $this->request('POST', '/x', ['text' => 'hi']));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testSendMessageEmptyTextReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'backend' => null, 'docState' => 10]);

        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['x']));
        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => '   ']));

        $this->assertSame(422, $this->statusOf($response));
    }
}

/**
 * In-memory LlmClient stub: emits the given deltas, then either throws or
 * returns a fixed result.
 */
final class FakeLlmClient implements LlmClient
{
    /** @param string[] $deltas */
    public function __construct(
        private array $deltas,
        private ?\Throwable $throw = null,
        private ?LlmChatResult $result = null,
    ) {}

    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult
    {
        foreach ($this->deltas as $delta) {
            $onTextDelta($delta);
        }
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->result
            ?? new LlmChatResult(implode('', $this->deltas), 42, 7, 'end_turn', 'claude-test');
    }
}
