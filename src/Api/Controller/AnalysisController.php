<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Ai\AIBackendDocument;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Shipard\Module\Core\Mail\ExtractedApplyOutcome;
use Shipard\Module\Core\Mail\ExtractedDocumentApplier;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;
use Shipard\Module\Core\Mail\ExtractedDocumentStatusResolver;
use Shipard\Module\Core\Mail\StatusWriteResult;

/**
 * Endpoints `/_mail/analysis/*` — pull-based protokol pro externí AI analyzer.
 *
 * Autentizace přes `shpd_ak_` token systémového uživatele `_ai_analyzer`
 * (viz `ai-analyzer-setup` CLI). Endpointy vyžadují `X-Claim-Token` hlavičku
 * (vyjma /queue a /claim).
 *
 * Spec: tasks/mail-phase3a.md §3.
 */
class AnalysisController
{
    public const MAIL_TABLE_ID = 303;

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const EXTRACTED_TABLE = 'core_mail_extracted_documents';
    private const BACKENDS_TABLE = 'core_ai_backends';
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';
    private const ATTACHMENTS_TABLE = 'core_attachments_files';
    private const HEADS_TABLE = 'docs_core_heads';

    // Workflow stavy zprávy (core.mail.docStatesIncoming) — pipeline na ně
    // sahá jediným místem: result s dokumenty posouvá Novou na K řešení.
    private const DOC_STATE_NEW = 10;
    private const DOC_STATE_IN_PROGRESS = 20;
    private const DOC_STATE_IN_PROGRESS_MAIN = 2;
    private const DOC_STATE_ARCHIVED = 80;
    private const DOC_STATE_TRASH = 90;

    // Pipeline status analýzy (core.mail.analysisStates) — ortogonální
    // ke workflow, řídí ho výhradně pipeline + reanalyze.
    public const ANALYSIS_NONE = 0;
    public const ANALYSIS_QUEUED = 10;
    public const ANALYSIS_ANALYZING = 20;
    public const ANALYSIS_ANALYZED = 30;
    public const ANALYSIS_FAILED = 70;

    private const DEFAULT_LEASE_SECONDS = 300;
    private const MIN_LEASE_SECONDS = 60;
    private const MAX_LEASE_SECONDS = 900;

    /** Sdílená pravidla confidence → status (viz ExtractedDocumentStatusResolver). */
    private ?ExtractedDocumentStatusResolver $statusResolver = null;

    /**
     * SchemaValidator + DocumentApplier are intentionally nullable for
     * back-compat with the Phase 1 wiring (and unit tests that don't need
     * either). When null, /result skips canonical validation and
     * /applyExtracted falls back to plain status update.
     *
     * @param array<string, TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DataSourceConfig $config,
        private readonly string $dsPath,
        private readonly array $tables,
        private readonly DocumentRegistry $documentRegistry,
        private readonly ?SchemaValidator $schemaValidator = null,
        private readonly ?DocumentApplier $applier = null,
        private readonly ?ConfigRuntime $configRuntime = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
        private readonly ?RowHistoryEnricher $enricher = null,
    ) {}

    // -------------------------------------------------------------------
    // Auth helpers
    // -------------------------------------------------------------------

    /**
     * Ověří, že volající je systémový uživatel _ai_analyzer (přes API key).
     */
    private function verifyAnalyzerAuth(AuthContext $auth): ?Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if ($auth->tokenType !== 'api_key') {
            return Response::error('UNAUTHORIZED', 'API key required', 401);
        }

        $user = $this->db->fetchRow(
            'SELECT login FROM core_system_users WHERE id = %i',
            $auth->userId,
        );
        if ($user === null || ($user['login'] ?? '') !== AIAnalyzerProvisioner::ANALYZER_LOGIN) {
            return Response::error(
                'FORBIDDEN',
                'This endpoint is restricted to the _ai_analyzer system user',
                403,
            );
        }

        return null;
    }

    /**
     * Načte aktivní claim podle X-Claim-Token a ověří shodu s message_ndx.
     * Vrací claim row, nebo Response s chybou (401/404/410).
     *
     * @return array<string, mixed>|Response
     */
    private function validateClaimToken(int $messageNdx, Request $request): array|Response
    {
        $token = $request->getHeader('X-Claim-Token');
        if ($token === null || trim($token) === '') {
            return Response::error('MISSING_CLAIM_TOKEN', 'X-Claim-Token header is required', 401);
        }

        $row = $this->db->fetchRow(
            'SELECT * FROM %n WHERE %n = %s LIMIT 1',
            self::CLAIMS_TABLE,
            'claim_token',
            trim($token),
        );

        if ($row === null) {
            return Response::error('INVALID_CLAIM_TOKEN', 'Claim token not found', 401);
        }

        if ((int) $row['message'] !== $messageNdx) {
            return Response::error('CLAIM_TOKEN_MISMATCH', 'Claim token does not match message', 401);
        }

        if ((int) $row['released'] === 1) {
            return Response::error('CLAIM_RELEASED', 'Claim has already been released', 410);
        }

        $expiresAt = strtotime((string) $row['expires_at']);
        if ($expiresAt === false || $expiresAt < time()) {
            return Response::error('CLAIM_EXPIRED', 'Claim has expired', 410);
        }

        return $row;
    }

    /**
     * Bezpečnostní hlavičky pro response, který může obsahovat tajemství
     * (claim s plaintext API klíčem). Spec §10 dec.2.
     */
    private function withNoStoreHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }

    private function clampLeaseSeconds(?int $requested): int
    {
        $seconds = $requested ?? self::DEFAULT_LEASE_SECONDS;
        return max(self::MIN_LEASE_SECONDS, min(self::MAX_LEASE_SECONDS, $seconds));
    }

    // -------------------------------------------------------------------
    // GET /queue
    // -------------------------------------------------------------------

    /**
     * Vrátí zprávy připravené k analýze. Spec §3.1.
     */
    public function queue(AuthContext $auth, Request $request): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? max(1, min(50, (int) $params['limit'])) : 5;

        // recommended_profile_ndx = profile_override zprávy NEBO default profile DS
        $defaultProfile = $this->db->fetchRow(
            'SELECT id FROM %n WHERE %n = %i AND %n = %i LIMIT 1',
            self::PROFILES_TABLE,
            'is_default',
            1,
            'is_active',
            1,
        );
        $defaultProfileId = $defaultProfile !== null ? (int) $defaultProfile['id'] : null;

        // Najdi zprávy ve frontě (analysis_state=10) mimo Archiv/Koš,
        // ai_analysis_enabled NOT FALSE (NULL nebo true), bez aktivní claim.
        // SQL inspirace: NOT EXISTS na claims s released=0 a expires_at
        // v budoucnu. docState se jinak nekontroluje — workflow je ortogonální.
        $now = date('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            'SELECT m.id AS ndx, m.received_at, m.subject, m.sender_email,
                    m.profile_override, m.raw_source_attachment
               FROM %n m
              WHERE m.analysis_state = %i
                AND m.docState NOT IN %in
                AND (m.ai_analysis_enabled IS NULL OR m.ai_analysis_enabled = %i)
                AND NOT EXISTS (
                    SELECT 1 FROM %n c
                     WHERE c.message = m.id
                       AND c.released = %i
                       AND c.expires_at > %s
                )
              ORDER BY m.received_at ASC, m.id ASC
              LIMIT %i',
            self::MESSAGES_TABLE,
            self::ANALYSIS_QUEUED,
            [self::DOC_STATE_ARCHIVED, self::DOC_STATE_TRASH],
            1,
            self::CLAIMS_TABLE,
            0,
            $now,
            $limit,
        );

        $messages = [];
        foreach ($rows as $row) {
            $messageNdx = (int) $row['ndx'];
            $attCount = (int) $this->db->fetchSingle(
                'SELECT COUNT(*) FROM %n WHERE %n = %i AND %n = %i',
                self::ATTACHMENTS_TABLE,
                'table_id',
                self::MAIL_TABLE_ID,
                'record_id',
                $messageNdx,
            );

            $recommendedProfile = !empty($row['profile_override'])
                ? (int) $row['profile_override']
                : $defaultProfileId;

            $messages[] = [
                'ndx' => $messageNdx,
                'received_at' => $this->normalizeDateTime($row['received_at']),
                'subject' => (string) $row['subject'],
                'sender_email' => (string) $row['sender_email'],
                'attachment_count' => $attCount,
                'recommended_profile_ndx' => $recommendedProfile,
                'has_raw_source' => !empty($row['raw_source_attachment']),
            ];
        }

        $totalAvailable = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n m
              WHERE m.analysis_state = %i
                AND m.docState NOT IN %in
                AND (m.ai_analysis_enabled IS NULL OR m.ai_analysis_enabled = %i)
                AND NOT EXISTS (
                    SELECT 1 FROM %n c
                     WHERE c.message = m.id
                       AND c.released = %i
                       AND c.expires_at > %s
                )',
            self::MESSAGES_TABLE,
            self::ANALYSIS_QUEUED,
            [self::DOC_STATE_ARCHIVED, self::DOC_STATE_TRASH],
            1,
            self::CLAIMS_TABLE,
            0,
            $now,
        );

        return Response::success([
            'messages' => $messages,
            'total_available' => $totalAvailable,
        ]);
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }
        return (string) $value;
    }

    // -------------------------------------------------------------------
    // POST /{ndx}/claim
    // -------------------------------------------------------------------

    /**
     * Atomic claim — ověř analysis_state=10 (ve frontě), žádná aktivní claim,
     * vytvoř claim record, přepni analysis_state→20, decryptuj api_key.
     * docState (workflow) se nemění. Spec §3.2.
     */
    public function claim(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $body = $request->getBody() ?? [];
        $analyzerId = trim((string) ($body['analyzer_id'] ?? ''));
        if ($analyzerId === '') {
            return Response::error(
                'VALIDATION_ERROR',
                'analyzer_id is required',
                422,
                [['field' => 'analyzer_id']],
            );
        }

        $requestedProfile = isset($body['profile_ndx']) ? (int) $body['profile_ndx'] : null;
        $leaseSeconds = $this->clampLeaseSeconds(
            isset($body['lease_seconds']) ? (int) $body['lease_seconds'] : null,
        );

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            // FOR UPDATE serializuje souběžné claim() přes řádek zprávy —
            // bez tohoto by dva analyzéry mohli oba projít SELECT a oba
            // INSERT do claims (tabulka nemá partial unique). Spec §3.2
            // "Atomicky" + §2.4 popis invariantu max-jedna-aktivní-claim.
            $msgRow = $dibi->fetch(
                'SELECT id, analysis_state, profile_override FROM %n WHERE id = %i FOR UPDATE',
                self::MESSAGES_TABLE,
                $messageNdx,
            );
            if ($msgRow === null) {
                $dibi->rollback();
                return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
            }
            if ((int) $msgRow['analysis_state'] !== self::ANALYSIS_QUEUED) {
                $dibi->rollback();
                return Response::error(
                    'INVALID_STATE',
                    'Message is not queued for analysis (analysis_state != 10)',
                    409,
                );
            }

            $now = date('Y-m-d H:i:s');
            $activeClaim = $dibi->fetch(
                'SELECT id FROM %n WHERE message = %i AND released = %i AND expires_at > %s LIMIT 1',
                self::CLAIMS_TABLE,
                $messageNdx,
                0,
                $now,
            );
            if ($activeClaim !== null) {
                $dibi->rollback();
                return Response::error('ALREADY_CLAIMED', 'Message already has an active claim', 409);
            }

            // Vyber profil + backend
            $profileNdx = $requestedProfile
                ?? (isset($msgRow['profile_override']) ? (int) $msgRow['profile_override'] : null);
            $profile = $this->resolveProfile($profileNdx);
            if ($profile === null) {
                $dibi->rollback();
                return Response::error(
                    'NO_PROFILE',
                    'No active profile available (default missing or requested profile invalid)',
                    409,
                );
            }
            $backend = $this->resolveBackend((int) $profile['backend']);
            if ($backend === null) {
                $dibi->rollback();
                return Response::error(
                    'NO_BACKEND',
                    'Profile references a backend that is missing or inactive',
                    409,
                );
            }

            // Decrypt api_key přes AIBackendDocument — single source of truth
            try {
                $cipher = DsSecretCipher::forConfig($this->config);
            } catch (SecretsKeyMissingException | SecretsKeyInsecureException $e) {
                $dibi->rollback();
                return Response::error(
                    'SECRETS_UNAVAILABLE',
                    'Server cannot decrypt backend API key: ' . $e->getMessage(),
                    500,
                );
            }
            $backendDoc = new AIBackendDocument();
            $backendDoc->setSecretCipher($cipher);
            try {
                $apiKey = $backendDoc->decryptApiKey($backend);
            } catch (InvalidCiphertextException $e) {
                $dibi->rollback();
                return Response::error(
                    'BACKEND_KEY_CORRUPTED',
                    'Stored API key cannot be decrypted (corrupted or wrong secrets.key)',
                    500,
                );
            }
            if ($apiKey === null || $apiKey === '') {
                $dibi->rollback();
                return Response::error(
                    'BACKEND_KEY_MISSING',
                    "Backend '{$backend['backend_id']}' has no API key set. Run ai-analyzer-set-key.",
                    409,
                );
            }

            // Vytvoř claim
            $claimToken = $this->generateClaimToken();
            $expiresAt = date('Y-m-d H:i:s', time() + $leaseSeconds);
            $dibi->insert(self::CLAIMS_TABLE, [
                'message' => $messageNdx,
                'analyzer_id' => $analyzerId,
                'claim_token' => $claimToken,
                'claimed_at' => $now,
                'expires_at' => $expiresAt,
                'released' => 0,
            ])->execute();

            // Přepni analýzu na "Analyzuje se" — docState zůstává
            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => self::ANALYSIS_ANALYZING,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            // Plaintext API key žije v této metodě v paměti — exception message
            // nesmí prosáknout do klienta (spec §10 dec.2). Detail loguj server-side.
            ErrorLogger::warn('AnalysisController::claim failed', [
                'error' => $e->getMessage(),
            ]);
            return Response::error('INTERNAL_ERROR', 'Internal server error during claim', 500);
        }

        $response = Response::success([
            'claim_token' => $claimToken,
            'expires_at' => $expiresAt,
            'profile' => [
                'profile_ndx' => (int) $profile['id'],
                'profile_id' => (string) $profile['profile_id'],
                'prompt_version' => (string) $profile['prompt_version'],
                'prompt_template' => (string) $profile['prompt_template'],
                'output_schema' => $this->decodeJsonField($profile['output_schema']),
                'supported_doc_types' => $this->decodeJsonField($profile['supported_doc_types']),
                'language' => (string) $profile['language'],
                'confidence_thresholds' => $this->decodeJsonField($profile['confidence_thresholds']),
            ],
            'backend' => [
                'backend_ndx' => (int) $backend['id'],
                'provider' => (string) $backend['provider'],
                'model' => (string) $backend['model'],
                'api_key' => $apiKey,
                'base_url' => $backend['base_url'] !== null ? (string) $backend['base_url'] : null,
                'max_tokens' => (int) $backend['max_tokens'],
                'temperature' => (float) $backend['temperature'],
            ],
        ]);

        return $this->withNoStoreHeaders($response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProfile(?int $requestedNdx): ?array
    {
        if ($requestedNdx !== null && $requestedNdx > 0) {
            $row = $this->db->fetchRow(
                'SELECT * FROM %n WHERE id = %i AND is_active = %i LIMIT 1',
                self::PROFILES_TABLE,
                $requestedNdx,
                1,
            );
            return $row;
        }

        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE is_default = %i AND is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            1,
            1,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBackend(int $backendNdx): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i AND is_active = %i LIMIT 1',
            self::BACKENDS_TABLE,
            $backendNdx,
            1,
        );
    }

    private function generateClaimToken(): string
    {
        return 'ct_' . bin2hex(random_bytes(30));
    }

    private function decodeJsonField(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    // -------------------------------------------------------------------
    // GET /{ndx}/payload
    // -------------------------------------------------------------------

    /**
     * Vrátí subject, body, sender + metadata příloh BEZ obsahu. Spec §3.3.
     */
    public function payload(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        $msg = $this->db->fetchRow(
            'SELECT subject, sender_email, sender_name, body_plain, body_html, received_at,
                    raw_source_attachment
               FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageNdx,
        );
        if ($msg === null) {
            return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
        }

        $rawSourceNdx = isset($msg['raw_source_attachment']) ? (int) $msg['raw_source_attachment'] : 0;

        $attRows = $this->db->fetchAll(
            'SELECT id, name, mime_type, file_size FROM %n
              WHERE %n = %i AND %n = %i AND id != %i AND is_deleted = %i
              ORDER BY id ASC',
            self::ATTACHMENTS_TABLE,
            'table_id',
            self::MAIL_TABLE_ID,
            'record_id',
            $messageNdx,
            $rawSourceNdx, // exclude raw .eml from analyzer-visible attachments
            0,
        );

        $attachments = [];
        foreach ($attRows as $att) {
            $attachments[] = [
                'ndx' => (int) $att['id'],
                'filename' => (string) $att['name'],
                'mime_type' => (string) $att['mime_type'],
                'size_bytes' => (int) $att['file_size'],
            ];
        }

        return Response::success([
            'message' => [
                'subject' => (string) $msg['subject'],
                'sender_email' => (string) $msg['sender_email'],
                'sender_name' => $msg['sender_name'] !== null ? (string) $msg['sender_name'] : null,
                'body_plain' => $msg['body_plain'] !== null ? (string) $msg['body_plain'] : null,
                'body_html' => $msg['body_html'] !== null ? (string) $msg['body_html'] : null,
                'received_at' => $this->normalizeDateTime($msg['received_at']),
            ],
            'attachments' => $attachments,
        ]);
    }

    // -------------------------------------------------------------------
    // GET /{ndx}/attachments/{att_ndx}/content
    // -------------------------------------------------------------------

    /**
     * Streamuje binární obsah jedné přílohy. Spec §3.4.
     * Validace: claim_token, attachment patří k messageNdx.
     */
    public function attachmentContent(
        AuthContext $auth,
        Request $request,
        int $messageNdx,
        int $attachmentNdx,
    ): Response {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        // Vyloučit raw_source_attachment (.eml originál) — analyzer pracuje
        // s rozparsovanými přílohami z /payload, ne s celým e-mailem (jinak
        // by se data analyzovala dvakrát: jako MIME příloha a jako součást .eml).
        $msgRow = $this->db->fetchRow(
            'SELECT raw_source_attachment FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageNdx,
        );
        if ($msgRow === null) {
            return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
        }
        $rawSourceNdx = isset($msgRow['raw_source_attachment'])
            ? (int) $msgRow['raw_source_attachment']
            : 0;
        if ($rawSourceNdx > 0 && $attachmentNdx === $rawSourceNdx) {
            return Response::error(
                'NOT_FOUND',
                'Raw source (.eml) is not exposed via this endpoint',
                404,
            );
        }

        $att = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i AND %n = %i AND %n = %i AND is_deleted = %i',
            self::ATTACHMENTS_TABLE,
            $attachmentNdx,
            'table_id',
            self::MAIL_TABLE_ID,
            'record_id',
            $messageNdx,
            0,
        );
        if ($att === null) {
            return Response::error(
                'NOT_FOUND',
                "Attachment {$attachmentNdx} not found for message {$messageNdx}",
                404,
            );
        }

        $service = new AttachmentService($this->db, $this->dsPath, $this->tables);
        $filePath = $service->getFilePath($att);
        if (!is_file($filePath)) {
            return Response::error('NOT_FOUND', 'Attachment file missing on disk', 404);
        }

        $this->streamFile(
            $filePath,
            (string) $att['mime_type'],
            (string) $att['name'],
            (int) $att['file_size'],
        );

        // streamFile exits — pro type safety
        return Response::success(null, 204);
    }

    private function streamFile(string $filePath, string $mimeType, string $displayName, int $fileSize): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mimeType);
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $displayName);
        header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($displayName));
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($filePath);
        exit;
    }

    // -------------------------------------------------------------------
    // POST /{ndx}/result
    // -------------------------------------------------------------------

    /**
     * Atomicky uloží výsledek analýzy: vytvoří záznam v message_analyses,
     * pro každý extracted_document vytvoří řádek se status podle confidence
     * vs profile thresholds, uvolní claim, přepne analysis_state→30.
     * docState: jen když je zpráva stále v Nové (10) a vznikl aspoň jeden
     * extracted document → 10→20 (K řešení). Prázdný result docState nemění
     * (zpráva zůstává v Nové — dashboard řeší karta „Není faktura");
     * ruční workflow stav pipeline nikdy nepřepisuje. Spec §3.5.
     */
    public function result(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        $body = $request->getBody() ?? [];
        $modelName = trim((string) ($body['model_name'] ?? ''));
        $promptVersion = trim((string) ($body['prompt_version'] ?? ''));
        if ($modelName === '' || $promptVersion === '') {
            return Response::error(
                'VALIDATION_ERROR',
                'model_name and prompt_version are required',
                422,
            );
        }

        $extractedDocsInput = is_array($body['extracted_documents'] ?? null)
            ? $body['extracted_documents']
            : [];

        // Načti profil — pro thresholds
        $profileNdx = isset($body['profile_ndx']) && (int) $body['profile_ndx'] > 0
            ? (int) $body['profile_ndx']
            : null;
        $backendNdx = isset($body['backend_ndx']) && (int) $body['backend_ndx'] > 0
            ? (int) $body['backend_ndx']
            : null;

        $thresholds = $this->statusResolver()->thresholdsForProfile($profileNdx);

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $now = date('Y-m-d H:i:s');

            // 1) message_analyses záznam
            $dibi->insert(self::ANALYSES_TABLE, [
                'message' => $messageNdx,
                'profile' => $profileNdx,
                'backend' => $backendNdx,
                'analyzed_at' => $now,
                'status' => 2, // success
                'model_name' => $modelName,
                'model_version' => isset($body['model_version']) ? (string) $body['model_version'] : null,
                'prompt_version' => $promptVersion,
                'analysis_json' => isset($body['analysis_json'])
                    ? (string) json_encode($body['analysis_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'confidence' => isset($body['overall_confidence']) ? (float) $body['overall_confidence'] : null,
                'tokens_input' => isset($body['tokens_input']) ? (int) $body['tokens_input'] : null,
                'tokens_output' => isset($body['tokens_output']) ? (int) $body['tokens_output'] : null,
                'duration_ms' => isset($body['duration_ms']) ? (int) $body['duration_ms'] : null,
                'cost_usd' => isset($body['cost_usd']) ? (float) $body['cost_usd'] : null,
                'extracted_document_count' => count($extractedDocsInput),
                'created' => $now,
                'created_by' => $auth->userId,
            ])->execute();
            $analysisNdx = (int) $dibi->getInsertId();

            // 2) extracted_documents — validate each canonical against the
            //    canonical schema; invalid output is preserved but flagged
            //    as STATUS_AI_FAILED so UI / reanalyze can deal with it.
            $extractedNdxs = [];
            foreach ($extractedDocsInput as $doc) {
                if (!is_array($doc)) {
                    continue;
                }
                $confidence = isset($doc['confidence']) ? (float) $doc['confidence'] : 0.0;

                $sourceAttachmentNdxs = is_array($doc['source_attachment_ndxs'] ?? null)
                    ? $doc['source_attachment_ndxs']
                    : [];

                $extractedJson = is_array($doc['extracted_json'] ?? null)
                    ? $doc['extracted_json']
                    : null;

                [$status, $jsonForDb] = $this->validateAndStoreCanonical(
                    $extractedJson,
                    $confidence,
                    $thresholds,
                );

                $dibi->insert(self::EXTRACTED_TABLE, [
                    'message' => $messageNdx,
                    'analysis' => $analysisNdx,
                    'doc_type' => trim((string) ($doc['doc_type'] ?? 'other')),
                    'source_attachments' => json_encode(
                        array_values(array_map('intval', $sourceAttachmentNdxs)),
                        JSON_UNESCAPED_UNICODE,
                    ),
                    'extracted_json' => $jsonForDb,
                    'confidence' => $confidence,
                    'status' => $status,
                    'created' => $now,
                    'created_by' => $auth->userId,
                ])->execute();
                $extractedNdxs[] = (int) $dibi->getInsertId();
            }

            // 3) Uvolni claim
            $dibi->update(self::CLAIMS_TABLE, [
                'released' => 1,
                'released_at' => $now,
                'release_reason' => 'result',
            ])->where('id = %i', (int) $claim['id'])->execute();

            // 4) analysis_state → 30 (Analyzováno), vynulovat needs_reanalysis.
            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => self::ANALYSIS_ANALYZED,
                'needs_reanalysis' => 0,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            // 5) Workflow: Nová → K řešení, jen když vznikl aspoň jeden
            // extracted document a uživatel mezitím stav ručně nezměnil
            // (docState != 10 → nechat být).
            if ($extractedDocsInput !== []) {
                $dibi->update(self::MESSAGES_TABLE, [
                    'docState' => self::DOC_STATE_IN_PROGRESS,
                    'docStateMain' => self::DOC_STATE_IN_PROGRESS_MAIN,
                ])
                ->where('id = %i', $messageNdx)
                ->where('docState = %i', self::DOC_STATE_NEW)
                ->execute();
            }

            // 6) Volitelná AI klasifikace typu zprávy (message_classification).
            $this->applyMessageClassification($dibi, $messageNdx, $body);

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        return Response::success([
            'analysis_ndx' => $analysisNdx,
            'extracted_document_ndxs' => $extractedNdxs,
        ], 201);
    }

    private function statusResolver(): ExtractedDocumentStatusResolver
    {
        return $this->statusResolver ??= new ExtractedDocumentStatusResolver($this->db);
    }

    /**
     * Zapíše volitelnou AI klasifikaci typu zprávy z `message_classification`
     * (spec tasks/mail-states-and-classification.md §B1). Běží uvnitř
     * transakce resultu.
     *
     * Analyzer daemon se kvůli klasifikaci neměnil — tělo /result staví sám
     * a nové top-level pole neposílá. Model output ale předává celý
     * v `analysis_json`, takže klasifikaci čteme odtud jako fallback;
     * top-level pole má přednost (budoucí analyzer ho může promotovat).
     *
     * - Klasifikace nikde není (starý prompt) → žádná změna.
     * - Neznámý `primary_type` → warning + ignore; nesmí rozbít uložení
     *   výsledku (žádná 422).
     * - AI nikdy nepřepisuje hodnotu nastavenou uživatelem
     *   (`primary_type_source = 'user'` → UPDATE se nedotkne řádku).
     *
     * @param array<string, mixed> $body
     */
    private function applyMessageClassification(\Dibi\Connection $dibi, int $messageNdx, array $body): void
    {
        $classification = $body['message_classification'] ?? null;
        if (!is_array($classification)) {
            $analysisJson = $body['analysis_json'] ?? null;
            $classification = is_array($analysisJson)
                ? ($analysisJson['message_classification'] ?? null)
                : null;
        }
        if (!is_array($classification)) {
            return;
        }

        $primaryType = trim((string) ($classification['primary_type'] ?? ''));
        if ($primaryType === '') {
            return;
        }

        if (!in_array($primaryType, $this->knownPrimaryTypes(), true)) {
            ErrorLogger::warn('AnalysisController::result ignoring unknown primary_type', [
                'messageNdx' => $messageNdx,
                'primary_type' => $primaryType,
            ]);
            return;
        }

        $dibi->update(self::MESSAGES_TABLE, [
            'primary_type' => $primaryType,
            'primary_type_source' => 'ai',
        ])
        ->where('id = %i', $messageNdx)
        ->where('primary_type_source != %s', 'user')
        ->execute();
    }

    /**
     * Klíče cfgItem `core.mail.primaryTypes` — server toleruje i typy
     * s `enabled: false` (prompt AI omezuje na enabled). Bez compiled
     * configu degraduje na pevný seznam (musí odpovídat primaryTypes.jsonc).
     *
     * @return list<string>
     */
    private function knownPrimaryTypes(): array
    {
        $cfg = $this->configRuntime?->cfgItem('core.mail.primaryTypes');
        if (is_array($cfg) && $cfg !== []) {
            return array_map('strval', array_keys($cfg));
        }

        return ['invoiceReceived', 'other', 'creditNote', 'order', 'quotation', 'statement', 'complaint'];
    }

    /**
     * Validate a canonical extracted by AI against shpd.docs.document.v1
     * schema and decide its status. Invalid output is wrapped (for
     * forensics) and flagged STATUS_AI_FAILED — never rejected outright,
     * so the user can still see what came out and trigger reanalyze.
     *
     * @param array<string, mixed>|null $extractedJson  Raw canonical from AI (or null).
     * @param array{ready: float, review: float} $thresholds
     * @return array{0: int, 1: ?string}  [status, jsonForDb]
     */
    private function validateAndStoreCanonical(
        ?array $extractedJson,
        float $confidence,
        array $thresholds,
    ): array {
        if ($extractedJson === null) {
            // No canonical at all — keep legacy behaviour: status by
            // confidence, NULL extracted_json. (Test result without canonical.)
            return [$this->statusResolver()->mapConfidenceToStatus($confidence, $thresholds), null];
        }

        // If no SchemaValidator was wired (e.g. unit tests), skip validation
        // and store as-is. This preserves Phase 1 behaviour for tests that
        // instantiate the controller without the Exchange dependencies.
        if ($this->schemaValidator === null) {
            return [
                $this->statusResolver()->mapConfidenceToStatus($confidence, $thresholds),
                (string) json_encode($extractedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $schemaIssues = $this->schemaValidator->validate(
            $extractedJson,
            DocumentApplier::FORMAT_ID,
            DocumentApplier::FORMAT_VERSION,
        );

        if ($schemaIssues === []) {
            $status = $this->statusResolver()->mapConfidenceToStatus($confidence, $thresholds);
            if ($this->enricher !== null) {
                // Obohacení řádků z historie (D2 persist) — do extracted_json
                // se ukládá obohacený canonical. Selhání /result nesmí shodit
                // (analyzer by zprávu retryoval) → pokračuje se neobohaceně.
                try {
                    $extractedJson = $this->enricher->enrich($extractedJson);
                } catch (\Throwable $e) {
                    ErrorLogger::logException($e, 'AnalysisController::result row history enrichment failed');
                }
                $status = $this->statusResolver()->capStatusByRowCoverage($status, $extractedJson);
            }
            return [
                $status,
                (string) json_encode($extractedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $wrapped = [
            '_validationError' => 'Canonical schema validation failed',
            '_validationIssues' => $schemaIssues,
            '_rawOutput' => $extractedJson,
        ];
        return [
            ExtractedDocumentDocument::STATUS_AI_FAILED,
            (string) json_encode($wrapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    // -------------------------------------------------------------------
    // POST /{ndx}/failed
    // -------------------------------------------------------------------

    /**
     * Atomicky uloží neúspěch analýzy: vytvoří failed analysis record,
     * uvolní claim, přepne analysis_state 20→10 (retryable) nebo 20→70
     * (permanent). docState se nemění. Spec §3.6.
     */
    public function failed(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        $authError = $this->verifyAnalyzerAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $claim = $this->validateClaimToken($messageNdx, $request);
        if ($claim instanceof Response) {
            return $claim;
        }

        $body = $request->getBody() ?? [];
        $errorType = trim((string) ($body['error_type'] ?? 'ai_error'));
        $errorMessage = trim((string) ($body['error_message'] ?? ''));
        $retryable = (bool) ($body['retryable'] ?? false);
        $tokensUsed = isset($body['tokens_used']) ? (int) $body['tokens_used'] : null;

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $now = date('Y-m-d H:i:s');

            $dibi->insert(self::ANALYSES_TABLE, [
                'message' => $messageNdx,
                'analyzed_at' => $now,
                'status' => 3, // failed
                'model_name' => isset($body['model_name']) ? (string) $body['model_name'] : 'unknown',
                'prompt_version' => isset($body['prompt_version']) ? (string) $body['prompt_version'] : 'unknown',
                'error_message' => $errorMessage !== '' ? "[{$errorType}] {$errorMessage}" : "[{$errorType}]",
                'tokens_input' => $tokensUsed,
                'created' => $now,
                'created_by' => $auth->userId,
            ])->execute();

            $dibi->update(self::CLAIMS_TABLE, [
                'released' => 1,
                'released_at' => $now,
                'release_reason' => 'failed',
            ])->where('id = %i', (int) $claim['id'])->execute();

            // retryable=true → zpět do fronty (10), jinak permanent error (70)
            $newState = $retryable ? self::ANALYSIS_QUEUED : self::ANALYSIS_FAILED;

            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => $newState,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        return Response::success([
            'message_ndx' => $messageNdx,
            'retryable' => $retryable,
            'new_state' => $retryable ? 'queued' : 'ai_failed',
        ], 200);
    }

    // -------------------------------------------------------------------
    // POST /_mail/messages/{ndx}/reanalyze
    // -------------------------------------------------------------------

    /**
     * UI akce "Znova analyzovat". Spec §4.
     *
     * Auth: běžný přihlášený uživatel (UI), ne _ai_analyzer.
     *
     * Validace: analysis_state ∈ {30 Analyzováno, 70 Analýza selhala}
     * a zpráva není v Archivu/Koši. Existující extracted_documents ve
     * statusech 10/20/30/70 → 60 (superseded); 40 (applied) a 50 (rejected)
     * zůstávají. Nastaví analysis_state→10, needs_reanalysis=true,
     * profile_override (volitelné). docState se nemění.
     */
    public function reanalyze(AuthContext $auth, Request $request, int $messageNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody() ?? [];
        $profileOverrideNdx = isset($body['profile_override_ndx']) && (int) $body['profile_override_ndx'] > 0
            ? (int) $body['profile_override_ndx']
            : null;

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            $msg = $dibi->fetch(
                'SELECT id, docState, analysis_state FROM %n WHERE id = %i',
                self::MESSAGES_TABLE,
                $messageNdx,
            );
            if ($msg === null) {
                $dibi->rollback();
                return Response::error('NOT_FOUND', "Message {$messageNdx} not found", 404);
            }

            $analysisState = (int) $msg['analysis_state'];
            $docState = (int) $msg['docState'];
            if (($analysisState !== self::ANALYSIS_ANALYZED && $analysisState !== self::ANALYSIS_FAILED)
                || $docState === self::DOC_STATE_ARCHIVED
                || $docState === self::DOC_STATE_TRASH
            ) {
                $dibi->rollback();
                return Response::error(
                    'INVALID_STATE',
                    'Reanalyze requires analysis_state 30 (Analyzováno) or 70 (Analýza selhala)'
                        . ' and a message outside Archive/Trash',
                    409,
                );
            }

            // Validuj profile override (pokud zadán)
            if ($profileOverrideNdx !== null) {
                $profile = $dibi->fetch(
                    'SELECT id FROM %n WHERE id = %i AND is_active = %i',
                    self::PROFILES_TABLE,
                    $profileOverrideNdx,
                    1,
                );
                if ($profile === null) {
                    $dibi->rollback();
                    return Response::error(
                        'INVALID_PROFILE',
                        "Profile {$profileOverrideNdx} not found or inactive",
                        422,
                    );
                }
            }

            $now = date('Y-m-d H:i:s');

            // Označit pending/ready/low + ai_failed extracted docs jako
            // superseded. AI_FAILED je legitimní cíl pro reanalyze —
            // jinak by ai_failed dokumenty navždy blokovaly auto-transition
            // zprávy 20→40 (afterPersist v ExtractedDocumentDocument
            // počítá s tím, že žádný sibling není v pending stavu).
            $supersededCount = $dibi->update(self::EXTRACTED_TABLE, [
                'status' => ExtractedDocumentDocument::STATUS_SUPERSEDED,
            ])
            ->where('message = %i', $messageNdx)
            ->where('status IN %in', [
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
                ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
                ExtractedDocumentDocument::STATUS_AI_FAILED,
            ])
            ->execute();

            // Vrátit analýzu do fronty — docState (workflow) zůstává
            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => self::ANALYSIS_QUEUED,
                'needs_reanalysis' => 1,
                'profile_override' => $profileOverrideNdx,
                'modified' => $now,
            ])->where('id = %i', $messageNdx)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        return Response::success([
            'message_ndx' => $messageNdx,
            'profile_override_ndx' => $profileOverrideNdx,
            'superseded_count' => (int) $supersededCount,
        ]);
    }

    // -------------------------------------------------------------------
    // POST /_mail/extracted-documents/{ndx}/apply  +  /reject
    // -------------------------------------------------------------------
    //
    // Pro UI akce "Použít" / "Zamítnout". Generický CrudController PATCH
    // obchází Document hooky (validate, beforeSave, afterPersist), takže
    // by se nespustil auto-transition zprávy 30→40 (spec §10 dec.4).
    // Tyto dedikované endpointy procházejí přes ExtractedDocumentDocument
    // a transakčně commitují i hook-vyvolaný UPDATE messages.

    public function applyExtracted(AuthContext $auth, Request $request, int $extractedNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        // Without an Applier wired (e.g. ConfigRuntime missing), fall back
        // to the legacy Phase 1 behaviour — pure status update. Lineage
        // won't be filled and no doc will be created, but the UI keeps
        // working.
        if ($this->applier === null) {
            $write = ExtractedDocumentApplier::writeStatusTransition(
                $this->db, $extractedNdx, $auth->userId,
                ExtractedDocumentDocument::STATUS_APPLIED, null,
            );
            return $this->statusWriteToResponse($write, $extractedNdx);
        }

        // Apply core lives in the shared service so this HTTP endpoint and
        // the MCP mail_draft_document tool run one code path. The controller
        // only parses the body and maps the outcome back onto a Response.
        $body = $request->getBody();
        $body = is_array($body) ? $body : [];
        $service = new ExtractedDocumentApplier($this->db, $this->applier, $this->enricher);
        $outcome = $service->apply(
            $extractedNdx,
            $auth->userId,
            array_key_exists('_resolve', $body) && is_array($body['_resolve']) ? $body['_resolve'] : null,
            is_array($body['applyOptions'] ?? null) ? $body['applyOptions'] : [],
        );

        return $this->outcomeToResponse($outcome);
    }

    /**
     * Map an {@see ExtractedApplyOutcome} onto the exact Response payloads /
     * HTTP statuses the endpoint produced before the service extraction.
     */
    private function outcomeToResponse(ExtractedApplyOutcome $outcome): Response
    {
        if (!$outcome->ok) {
            return Response::error(
                $outcome->errorCode ?? 'INTERNAL_ERROR',
                $outcome->errorMessage ?? 'Apply failed',
                $outcome->statusCode,
                $outcome->canonical !== null ? ['canonical' => $outcome->canonical] : [],
            );
        }

        $payload = [
            'savedDocId'   => (int) ($outcome->savedDocId ?? 0),
            'extractedNdx' => $outcome->extractedNdx,
            'messageNdx'   => $outcome->messageNdx,
        ];
        if ($outcome->idempotent) {
            $payload['idempotent'] = true;
        } elseif ($outcome->recovered) {
            $payload['recovered'] = true;
        } else {
            $payload['canonical'] = $outcome->canonical;
        }
        return Response::success($payload);
    }

    public function rejectExtracted(AuthContext $auth, Request $request, int $extractedNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody() ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            return Response::error(
                'VALIDATION_ERROR',
                'reason is required',
                422,
                [['field' => 'reason']],
            );
        }

        $write = ExtractedDocumentApplier::writeStatusTransition(
            $this->db, $extractedNdx, $auth->userId,
            ExtractedDocumentDocument::STATUS_REJECTED, $reason,
        );
        return $this->statusWriteToResponse($write, $extractedNdx);
    }

    /**
     * Undo apply (dashboard feed „Vrátit"): cílový Koncept do Koše, extracted
     * zpět na pending_review. Viz {@see ExtractedDocumentApplier::unapply}.
     */
    public function unapplyExtracted(AuthContext $auth, Request $request, int $extractedNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $headsGateway = $this->buildHeadsGateway();
        if ($headsGateway === null) {
            return Response::error('INTERNAL_ERROR', 'Document gateway unavailable', 500);
        }

        $outcome = ExtractedDocumentApplier::unapply(
            $this->db, $extractedNdx, $auth->userId, $headsGateway,
        );
        if (!$outcome->ok) {
            return Response::error(
                $outcome->errorCode ?? 'INTERNAL_ERROR',
                $outcome->errorMessage ?? 'Unapply failed',
                $outcome->statusCode,
            );
        }

        return Response::success([
            'ndx'          => $outcome->extractedNdx,
            'status'       => ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
            'messageNdx'   => $outcome->messageNdx,
            'trashedDocId' => (int) ($outcome->savedDocId ?? 0),
        ]);
    }

    /**
     * Postaví TableGateway nad `docs_core_heads` pro přesun cílového dokladu do
     * Koše přes Document flow (paralela k
     * `FormController::applyStateTransitionViaDocument`). Vrací null, když
     * definice tabulky chybí (modul docs vypnutý).
     */
    private function buildHeadsGateway(): ?TableGateway
    {
        $def = $this->tables[self::HEADS_TABLE] ?? null;
        if ($def === null) {
            return null;
        }
        return new TableGateway(
            self::HEADS_TABLE,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $def->childTables,
            $this->configRuntime,
            $this->config,
            $this->eventDispatcher,
            $def->docStates,
        );
    }

    /**
     * Map a {@see StatusWriteResult} onto the Response shape the legacy
     * `updateExtractedStatus` produced — used by reject and the no-applier
     * apply fallback.
     */
    private function statusWriteToResponse(StatusWriteResult $write, int $extractedNdx): Response
    {
        if ($write->notFound) {
            return Response::error('NOT_FOUND', "Extracted document {$extractedNdx} not found", 404);
        }
        if (!$write->ok) {
            if ($write->validationErrors !== null) {
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $write->validationErrors);
            }
            return Response::error(
                $write->errorCode ?? 'INTERNAL_ERROR',
                $write->errorMessage ?? 'Internal server error',
                $write->statusCode,
            );
        }
        return Response::success([
            'ndx'         => $extractedNdx,
            'status'      => $write->newStatus,
            'message_ndx' => $write->messageNdx,
        ]);
    }

    /**
     * Read-only preview of an extracted document — returns enriched
     * canonical with `_resolve` populated for the UI split-view modal.
     * Server-side injection of `source.extractedDoc` + informative
     * `applyOptions` mirrors {@see applyExtracted} so the preview reflects
     * how an apply would run (without doing the side-creates/save).
     *
     * For `STATUS_AI_FAILED` rows (where extracted_json was wrapped during
     * /result validation), returns the wrapper directly so the UI can
     * render its dedicated error view.
     */
    public function previewExtracted(AuthContext $auth, Request $request, int $extractedNdx): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $existing = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::EXTRACTED_TABLE, $extractedNdx,
        );
        if ($existing === null) {
            return Response::error('NOT_FOUND', "Extracted document {$extractedNdx} not found", 404);
        }

        $extractedJson = json_decode((string) ($existing['extracted_json'] ?? ''), true);
        if (!is_array($extractedJson)) {
            return Response::error('CORRUPTED_DATA', 'extracted_json cannot be parsed', 500);
        }

        $attachmentNdxs = $this->parseSourceAttachments((string) ($existing['source_attachments'] ?? '[]'));
        $attachments = $this->loadAttachmentsMeta($attachmentNdxs);

        $currentStatus = (int) $existing['status'];

        // ai_failed → return wrapper for the special UI render path
        if ($currentStatus === ExtractedDocumentDocument::STATUS_AI_FAILED
            && isset($extractedJson['_validationError'])
        ) {
            return Response::success([
                'aiFailed'      => true,
                'wrapper'       => $extractedJson,
                'attachments'   => $attachments,
                'extractedNdx'  => $extractedNdx,
                'messageNdx'    => (int) $existing['message'],
                'status'        => $currentStatus,
            ]);
        }

        // Without applier wired (e.g. ConfigRuntime missing), return raw
        // canonical without resolve — the UI can still render the read-only
        // view, just without resolve badges.
        if ($this->applier === null) {
            return Response::success([
                'aiFailed'     => false,
                'canonical'    => $extractedJson,
                'attachments'  => $attachments,
                'extractedNdx' => $extractedNdx,
                'messageNdx'   => (int) $existing['message'],
                'status'       => $currentStatus,
            ]);
        }

        // Server-controlled injection — applier preview is informative, so
        // applyOptions are advisory (they would only matter for /apply).
        $canonical = $extractedJson;
        $canonical['source'] = is_array($canonical['source'] ?? null) ? $canonical['source'] : [];
        $canonical['source']['extractedDoc'] = $extractedNdx;
        if (empty($canonical['source']['kind'])) {
            $canonical['source']['kind'] = 'aiExtraction';
        }
        $canonical['applyOptions'] = [
            'autoCreateMode' => 'safe',
            'targetDocState' => 10,
        ];

        // Fresh obohacení z historie (D2) — přepíše persistnutý enrichment
        // blok aktuálním stavem DB. Selhání preview neblokuje.
        if ($this->enricher !== null) {
            try {
                $canonical = $this->enricher->enrich($canonical);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'AnalysisController::previewExtracted row history enrichment failed');
            }
        }

        $result = $this->applier->preview($canonical);
        if (!$result->success) {
            // preview() should always succeed (resolve issues live in
            // _resolve.issues, not errorCode), but propagate defensively.
            return Response::error(
                $result->errorCode ?? 'INTERNAL_ERROR',
                $result->errorMessage ?? 'Preview failed',
                $result->statusCode,
                ['canonical' => $result->canonical],
            );
        }

        return Response::success([
            'aiFailed'     => false,
            'canonical'    => $result->canonical,
            'attachments'  => $attachments,
            'extractedNdx' => $extractedNdx,
            'messageNdx'   => (int) $existing['message'],
            'status'       => $currentStatus,
        ]);
    }

    /**
     * Parse `source_attachments` JSON column into a flat list of positive
     * attachment ids.
     *
     * @return list<int>
     */
    private function parseSourceAttachments(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            $n = (int) $v;
            if ($n > 0) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * Fetch attachment metadata for the UI PDF viewer panel. Restricted to
     * the mail table (table_id = 303) so unrelated attachments can't leak
     * via crafted source_attachments.
     *
     * @param list<int> $ndxs
     * @return array<int, array{ndx: int, filename: string, mime_type: string, size_bytes: int}>
     */
    private function loadAttachmentsMeta(array $ndxs): array
    {
        if ($ndxs === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, name, mime_type, file_size
             FROM %n
             WHERE id IN %in AND table_id = %i AND is_deleted = %i
             ORDER BY id ASC',
            self::ATTACHMENTS_TABLE, $ndxs, self::MAIL_TABLE_ID, 0,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ndx'        => (int) $row['id'],
                'filename'   => (string) $row['name'],
                'mime_type'  => (string) $row['mime_type'],
                'size_bytes' => (int) $row['file_size'],
            ];
        }
        return $out;
    }
}
