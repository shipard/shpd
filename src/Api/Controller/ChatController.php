<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Ai\Exception\LlmException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Ai\AIBackendDocument;

/**
 * API controller for chat conversation management (Phase 1).
 *
 * Endpoints (all require auth, all user-scoped — a user only ever sees
 * their own conversations):
 *   GET    /_chat/conversations        List the user's conversations
 *   POST   /_chat/conversations        Create an empty conversation
 *   GET    /_chat/conversations/{id}   Conversation detail + its messages
 *   PATCH  /_chat/conversations/{id}   Rename (title)
 *   DELETE /_chat/conversations/{id}   Soft-delete (docState = 90)
 *
 * Messages are NOT writable through the API — they are produced by the LLM
 * loop (Phase 2), which guarantees the integrity of the Anthropic content
 * blocks. This controller only reads them (in the detail endpoint).
 */
class ChatController
{
    private const TABLE_CONVERSATIONS = 'core_chat_conversations';
    private const TABLE_MESSAGES       = 'core_chat_messages';
    private const DOC_STATES_CFG       = 'core.system.docStatesArchive';
    private const STATE_ACTIVE         = 10;
    private const STATE_DELETED        = 90;
    private const DEFAULT_LIMIT        = 50;
    private const MAX_LIMIT            = 200;
    private const BACKENDS_TABLE       = 'core_ai_backends';

    private const SYSTEM_PROMPT_FALLBACK =
        'Jsi vestavěný AI asistent účetního systému Shipard. Pomáháš uživateli '
        . 's dotazy o jeho datech a agendě. Odpovídej věcně, stručně a česky. '
        . 'Odpověz přímo, bez zbytečného úvodu.';

    public function __construct(
        protected DataSourceConnection $db,
        private ?ConfigRuntime $config = null,
        private ?DataSourceConfig $dsConfig = null,
        private ?LlmClient $llm = null,
    ) {}

    /**
     * GET /_chat/conversations[?limit=&offset=]
     * Lists the user's non-deleted conversations, newest activity first.
     */
    public function list(AuthContext $auth, Request $request): Response
    {
        $userId = $this->requireUser($auth);
        if ($userId instanceof Response) {
            return $userId;
        }

        $params = $request->getQueryParams();
        $limit  = min(max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $rows = $this->db->fetchAll(
            'SELECT `id`, `title`, `backend`, `model_snapshot`,'
            . ' `tokens_input`, `tokens_output`, `cost`, `created`, `modified`'
            . ' FROM `' . self::TABLE_CONVERSATIONS . '`'
            . ' WHERE `user` = %i AND `docState` <> %i'
            . ' ORDER BY `modified` DESC, `id` DESC'
            . ' LIMIT %i OFFSET %i',
            $userId,
            self::STATE_DELETED,
            $limit,
            $offset,
        );

        return Response::success(array_map([$this, 'formatConversation'], $rows));
    }

    /**
     * POST /_chat/conversations
     * Body: { "title"?: string|null, "backend"?: int|null }
     * Creates an empty conversation owned by the current user.
     */
    public function create(AuthContext $auth, Request $request): Response
    {
        $userId = $this->requireUser($auth);
        if ($userId instanceof Response) {
            return $userId;
        }

        $body    = $request->getBody() ?? [];
        $title   = $this->normalizeTitle($body['title'] ?? null);
        $backend = isset($body['backend']) && $body['backend'] !== null
            ? (int) $body['backend']
            : null;

        $now = date('Y-m-d H:i:s');
        $id  = $this->db->insertRow(self::TABLE_CONVERSATIONS, [
            'user'          => $userId,
            'title'         => $title,
            'backend'       => $backend,
            'tokens_input'  => 0,
            'tokens_output' => 0,
            'cost'          => 0,
            'created'       => $now,
            'created_by'    => $userId,
            'modified'      => $now,
            'docState'      => self::STATE_ACTIVE,
            'docStateMain'  => $this->mainState(self::STATE_ACTIVE),
        ]);

        return Response::success(['id' => $id], 201);
    }

    /**
     * GET /_chat/conversations/{id}
     * Returns the conversation header plus its messages ordered by seq.
     * A conversation owned by someone else (or missing) yields 404.
     */
    public function show(AuthContext $auth, int $id): Response
    {
        $userId = $this->requireUser($auth);
        if ($userId instanceof Response) {
            return $userId;
        }

        $conversation = $this->loadOwnedConversation($id, $userId);
        if ($conversation === null) {
            return Response::error('NOT_FOUND', 'Conversation not found', 404);
        }

        $messages = $this->db->fetchAll(
            'SELECT `id`, `seq`, `role`, `kind`, `content`,'
            . ' `tokens_input`, `tokens_output`, `cost`, `model_name`, `created`'
            . ' FROM `' . self::TABLE_MESSAGES . '`'
            . ' WHERE `conversation` = %i'
            . ' ORDER BY `seq` ASC, `id` ASC',
            $id,
        );

        return Response::success([
            'conversation' => $this->formatConversation($conversation),
            'messages'     => array_map([$this, 'formatMessage'], $messages),
        ]);
    }

    /**
     * PATCH /_chat/conversations/{id}
     * Body: { "title": string|null } — renames the conversation.
     */
    public function rename(AuthContext $auth, int $id, Request $request): Response
    {
        $userId = $this->requireUser($auth);
        if ($userId instanceof Response) {
            return $userId;
        }

        $conversation = $this->loadOwnedConversation($id, $userId);
        if ($conversation === null) {
            return Response::error('NOT_FOUND', 'Conversation not found', 404);
        }

        $body = $request->getBody();
        if ($body === null || !array_key_exists('title', $body)) {
            return Response::error('VALIDATION_ERROR', 'Missing title', 422);
        }

        $this->db->updateWhere(
            self::TABLE_CONVERSATIONS,
            [
                'title'    => $this->normalizeTitle($body['title']),
                'modified' => date('Y-m-d H:i:s'),
            ],
            '`id` = %i',
            $id,
        );

        return Response::success(['id' => $id]);
    }

    /**
     * DELETE /_chat/conversations/{id}
     * Soft-delete — sets docState = 90; the row is never physically removed.
     */
    public function delete(AuthContext $auth, int $id): Response
    {
        $userId = $this->requireUser($auth);
        if ($userId instanceof Response) {
            return $userId;
        }

        $conversation = $this->loadOwnedConversation($id, $userId);
        if ($conversation === null) {
            return Response::error('NOT_FOUND', 'Conversation not found', 404);
        }

        $this->db->updateWhere(
            self::TABLE_CONVERSATIONS,
            [
                'docState'     => self::STATE_DELETED,
                'docStateMain' => $this->mainState(self::STATE_DELETED),
                'modified'     => date('Y-m-d H:i:s'),
            ],
            '`id` = %i',
            $id,
        );

        return Response::success(null, 200);
    }

    /**
     * POST /_chat/conversations/{id}/messages
     * Body: { "text": string }
     *
     * Persists the user message immediately, then streams the assistant reply
     * over SSE (text-delta → message-complete | error). The user message
     * survives a model failure; the assistant message is written on completion.
     */
    public function sendMessage(AuthContext $auth, int $id, Request $request): Response
    {
        $userId = $this->requireUser($auth);
        if ($userId instanceof Response) {
            return $userId;
        }

        $conversation = $this->loadOwnedConversation($id, $userId);
        if ($conversation === null) {
            return Response::error('NOT_FOUND', 'Conversation not found', 404);
        }

        if ($this->llm === null) {
            return Response::error('LLM_NOT_CONFIGURED', 'Chat LLM is not configured', 503);
        }

        $body = $request->getBody() ?? [];
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '') {
            return Response::error('VALIDATION_ERROR', 'Message text is required', 422);
        }

        // Persist the user message before calling the model (resilience).
        $this->insertMessage($id, $userId, 'user', 'user_text', [['type' => 'text', 'text' => $text]], $this->nextSeq($id));

        $backend = $this->resolveBackend($conversation);
        if ($backend === null) {
            return Response::error('NO_BACKEND', 'No active AI backend is configured', 503);
        }

        try {
            $apiKey = $this->resolveApiKey($backend);
        } catch (\Throwable $e) {
            ErrorLogger::warn('ChatController: backend key decrypt failed', ['error' => $e->getMessage()]);
            return Response::error('BACKEND_KEY_ERROR', 'Cannot read backend API key', 500);
        }

        $params = new LlmChatParams(
            provider: (string) ($backend['provider'] ?? 'anthropic'),
            model: (string) ($backend['model'] ?? ''),
            apiKey: $apiKey,
            baseUrl: $backend['base_url'] !== null ? (string) $backend['base_url'] : '',
            system: $this->systemPrompt(),
            messages: $this->buildAnthropicMessages($id),
            maxTokens: (int) ($backend['max_tokens'] ?? 4096),
            temperature: null, // v1: omitted — Opus 4.7/4.8 reject `temperature` (HTTP 400)
        );

        return Response::stream(
            fn () => $this->runStream($params, $id, $userId),
            200,
            'text/event-stream; charset=utf-8',
        );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Drives the streaming turn: emits text-delta events, persists the
     * assistant message + conversation aggregates, then emits message-complete.
     * Any failure becomes an `error` event; the user message is already saved
     * and any partial assistant text is persisted best-effort.
     */
    private function runStream(LlmChatParams $params, int $conversationId, int $userId): void
    {
        $accumulated = '';
        try {
            /** @var LlmClient $llm */
            $llm = $this->llm;
            $result = $llm->streamChat($params, function (string $delta) use (&$accumulated): void {
                $accumulated .= $delta;
                $this->sse('text-delta', ['text' => $delta]);
            });

            $messageId = $this->insertMessage(
                $conversationId,
                $userId,
                'assistant',
                'assistant',
                [['type' => 'text', 'text' => $result->text]],
                $this->nextSeq($conversationId),
                $result,
            );
            $this->bumpConversation($conversationId, $result);

            $this->sse('message-complete', [
                'message_id' => $messageId,
                'usage' => [
                    'input_tokens'  => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                ],
                'model' => $result->model,
            ]);
        } catch (\Throwable $e) {
            // User message is already persisted; keep partial assistant text too.
            if ($accumulated !== '') {
                try {
                    $this->insertMessage(
                        $conversationId,
                        $userId,
                        'assistant',
                        'assistant',
                        [['type' => 'text', 'text' => $accumulated]],
                        $this->nextSeq($conversationId),
                    );
                } catch (\Throwable) {
                    // best-effort — do not mask the original error
                }
            }

            if ($e instanceof LlmException) {
                $this->sse('error', ['code' => 'LLM_ERROR', 'message' => $e->getMessage()]);
            } else {
                ErrorLogger::warn('ChatController: stream failed', ['error' => $e->getMessage()]);
                $this->sse('error', ['code' => 'INTERNAL_ERROR', 'message' => 'Internal error during chat']);
            }
        }
    }

    /** Writes one SSE event frame and flushes it to the client. */
    private function sse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }

    /** Next sequence number for a conversation (0-based, gap-free per insert order). */
    private function nextSeq(int $conversationId): int
    {
        $max = $this->db->fetchSingle(
            'SELECT MAX(`seq`) FROM `' . self::TABLE_MESSAGES . '` WHERE `conversation` = %i',
            $conversationId,
        );
        return $max === null ? 0 : ((int) $max + 1);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     */
    private function insertMessage(
        int $conversationId,
        int $userId,
        string $role,
        string $kind,
        array $content,
        int $seq,
        ?LlmChatResult $result = null,
    ): int {
        return $this->db->insertRow(self::TABLE_MESSAGES, [
            'conversation'  => $conversationId,
            'seq'           => $seq,
            'role'          => $role,
            'kind'          => $kind,
            'content'       => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tokens_input'  => $result?->inputTokens,
            'tokens_output' => $result?->outputTokens,
            'cost'          => null,
            'model_name'    => $result?->model,
            'created'       => date('Y-m-d H:i:s'),
            'created_by'    => $userId,
        ]);
    }

    /** Adds the turn's usage onto the conversation aggregates. */
    private function bumpConversation(int $conversationId, LlmChatResult $result): void
    {
        $this->db->execute(
            'UPDATE `' . self::TABLE_CONVERSATIONS . '`'
            . ' SET `tokens_input` = `tokens_input` + %i,'
            . '     `tokens_output` = `tokens_output` + %i,'
            . '     `model_snapshot` = %sN,'
            . '     `modified` = %s'
            . ' WHERE `id` = %i',
            $result->inputTokens ?? 0,
            $result->outputTokens ?? 0,
            $result->model,
            date('Y-m-d H:i:s'),
            $conversationId,
        );
    }

    /**
     * Backend = conversation.backend if set and active, else the default active
     * backend. Returns null when no active backend exists.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>|null
     */
    private function resolveBackend(array $conversation): ?array
    {
        $backendId = isset($conversation['backend']) && $conversation['backend'] !== null
            ? (int) $conversation['backend']
            : 0;

        if ($backendId > 0) {
            $row = $this->db->fetchRow(
                'SELECT * FROM `' . self::BACKENDS_TABLE . '` WHERE `id` = %i AND `is_active` = %i LIMIT 1',
                $backendId,
                1,
            );
            if ($row !== null) {
                return $row;
            }
        }

        return $this->db->fetchRow(
            'SELECT * FROM `' . self::BACKENDS_TABLE . '` WHERE `is_default` = %i AND `is_active` = %i LIMIT 1',
            1,
            1,
        );
    }

    /**
     * Decrypts the backend API key. Builds the cipher only when a key is set,
     * so backends without a key (or test fixtures) need no secrets infra.
     *
     * @param array<string, mixed> $backend
     */
    private function resolveApiKey(array $backend): ?string
    {
        if (!isset($backend['api_key']) || $backend['api_key'] === null || $backend['api_key'] === '') {
            return null;
        }
        if ($this->dsConfig === null) {
            throw new \RuntimeException('DataSourceConfig is required to decrypt the backend API key');
        }
        $doc = new AIBackendDocument();
        $doc->setSecretCipher(DsSecretCipher::forConfig($this->dsConfig));
        return $doc->decryptApiKey($backend);
    }

    /**
     * Builds the Anthropic `messages` array from the full conversation history.
     * Stored `content` is already a list of Anthropic blocks.
     *
     * @return array<int, array{role: string, content: mixed}>
     */
    private function buildAnthropicMessages(int $conversationId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `role`, `content` FROM `' . self::TABLE_MESSAGES . '`'
            . ' WHERE `conversation` = %i ORDER BY `seq` ASC, `id` ASC',
            $conversationId,
        );

        $messages = [];
        foreach ($rows as $row) {
            $content = $row['content'] ?? '';
            $decoded = is_string($content) && $content !== '' ? json_decode($content, true) : [];
            $messages[] = [
                'role'    => (string) $row['role'],
                'content' => is_array($decoded) ? $decoded : [],
            ];
        }
        return $messages;
    }

    /** System prompt from the chat config cfgItem, with a built-in fallback. */
    private function systemPrompt(): string
    {
        if ($this->config !== null) {
            $cfg = $this->config->cfgItem('core.chat.settings');
            if (is_array($cfg) && !empty($cfg['systemPrompt'])) {
                return (string) $cfg['systemPrompt'];
            }
        }
        return self::SYSTEM_PROMPT_FALLBACK;
    }

    /**
     * Returns the authenticated user id, or a 401 Response when missing.
     */
    private function requireUser(AuthContext $auth): int|Response
    {
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        return $auth->userId;
    }

    /**
     * Loads a non-deleted conversation owned by the given user. Returns null
     * when it does not exist, belongs to someone else, or is soft-deleted —
     * the caller maps all of these to 404 so ownership is not leaked.
     *
     * @return array<string, mixed>|null
     */
    private function loadOwnedConversation(int $id, int $userId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM `' . self::TABLE_CONVERSATIONS . '`'
            . ' WHERE `id` = %i AND `user` = %i AND `docState` <> %i',
            $id,
            $userId,
            self::STATE_DELETED,
        );
    }

    /** Maps a docState to its mainState via the docStates cfgItem (default 1). */
    private function mainState(int $docState): int
    {
        $cfg = DocStateConfig::fromCfgItem(
            $this->config !== null ? $this->config->cfgItem(self::DOC_STATES_CFG) : null,
        );
        return $cfg->getMainState($docState);
    }

    private function normalizeTitle(mixed $title): ?string
    {
        if ($title === null) {
            return null;
        }
        $title = trim((string) $title);
        if ($title === '') {
            return null;
        }
        return mb_substr($title, 0, 200);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatConversation(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'title'          => $row['title'] !== null ? (string) $row['title'] : null,
            'backend'        => isset($row['backend']) && $row['backend'] !== null ? (int) $row['backend'] : null,
            'model_snapshot' => $row['model_snapshot'] !== null ? (string) $row['model_snapshot'] : null,
            'tokens_input'   => (int) ($row['tokens_input'] ?? 0),
            'tokens_output'  => (int) ($row['tokens_output'] ?? 0),
            'cost'           => (float) ($row['cost'] ?? 0),
            'created'        => $row['created'] ?? null,
            'modified'       => $row['modified'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatMessage(array $row): array
    {
        $content = $row['content'] ?? '';
        $decoded = is_string($content) && $content !== '' ? json_decode($content, true) : [];

        return [
            'id'            => (int) $row['id'],
            'seq'           => (int) $row['seq'],
            'role'          => (string) $row['role'],
            'kind'          => (string) $row['kind'],
            'content'       => is_array($decoded) ? $decoded : [],
            'tokens_input'  => isset($row['tokens_input']) && $row['tokens_input'] !== null ? (int) $row['tokens_input'] : null,
            'tokens_output' => isset($row['tokens_output']) && $row['tokens_output'] !== null ? (int) $row['tokens_output'] : null,
            'cost'          => isset($row['cost']) && $row['cost'] !== null ? (float) $row['cost'] : null,
            'model_name'    => $row['model_name'] ?? null,
            'created'       => $row['created'] ?? null,
        ];
    }
}
