<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocStateConfig;

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

    public function __construct(
        protected DataSourceConnection $db,
        private ?ConfigRuntime $config = null,
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

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

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
