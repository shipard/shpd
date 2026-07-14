<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\IdempotencyStore;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Shipard\Module\Core\Mail\IsdocImportService;
use Shipard\Module\Core\Mail\MailRouterProvisioner;

/**
 * Endpoint `POST /_mail/incoming` — příjem došlé pošty z externí služby
 * shipard-mail-router. Request je multipart/form-data s povinnými poli
 * `received_at`, `sender_email`, `raw_source` (.eml) a 0..N přílohami
 * v poli `attachments[]`.
 *
 * Viz `tasks/mail-phase2a.md` § 2 — API kontrakt.
 */
class MailController
{
    private const MAIL_TABLE = 'core_mail_incoming_messages';
    private const MAIL_TABLE_ID = 303;

    private AttachmentService $attachments;
    private IdempotencyStore $idempotency;

    /**
     * @param array<string, TableDefinition> $tables
     * @param \Closure(): IsdocImportService|null $isdocImportFactory Lazy
     *        wiring deterministického ISDOC importu — service se staví až
     *        při prvním kandidátovi (intake bez ISDOC neplatí režii wiringu).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $dsPath,
        private readonly array $tables,
        private readonly DocumentRegistry $documentRegistry,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?\Closure $isdocImportFactory = null,
    ) {
        $this->attachments = new AttachmentService($db, $dsPath, $tables);
        $this->idempotency = new IdempotencyStore($db);
    }

    /**
     * `POST /_mail/senders/{id}/password` — jediná cesta, jak nastavit
     * SMTP heslo senderu. Sloupec `password_enc` je sensitive (generické
     * CRUD ho nečte ani nezapíše); plaintext z body se zašifruje
     * DsSecretCipher a uloží. Admin session only — API klíče nikdy
     * nemají isAdmin.
     */
    public function setSenderPassword(AuthContext $auth, Request $request, int $id): Response
    {
        if (!$auth->isAuthenticated || !$auth->isAdmin) {
            return Response::error('FORBIDDEN', 'Administrator session required', 403);
        }

        $body = $request->getBody();
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($password === '') {
            return Response::error('VALIDATION', "Field 'password' is required and must be a non-empty string", 400);
        }

        $sender = $this->db->fetchRow('SELECT id FROM core_mail_senders WHERE id = %i', $id);
        if ($sender === null) {
            return Response::error('NOT_FOUND', "Sender #{$id} not found", 404);
        }

        try {
            $cipher = DsSecretCipher::forConfig($this->dsConfig ?? new DataSourceConfig($this->dsPath));
            $encrypted = $cipher->encrypt($password);
        } catch (\Throwable $e) {
            // Chybu šifrování vracíme bez detailů — nesmí uniknout nic o klíči.
            return Response::error('INTERNAL_ERROR', 'Failed to encrypt the password: ' . $e->getMessage(), 500);
        }

        $this->db->updateWhere('core_mail_senders', ['password_enc' => $encrypted], 'id = %i', $id);

        return Response::success(['id' => $id, 'passwordSet' => true]);
    }

    public function receiveIncoming(AuthContext $auth, Request $request): Response
    {
        $authError = $this->verifyAuth($auth);
        if ($authError !== null) {
            return $authError;
        }

        $idempotencyKey = $this->extractIdempotencyKey($request);

        if ($idempotencyKey !== null) {
            $cached = $this->idempotency->lookup($idempotencyKey);
            if ($cached !== null) {
                return $this->replayResponse($cached['response_body']);
            }
        }

        $validation = $this->validateFormFields();
        if ($validation instanceof Response) {
            return $validation;
        }
        $fields = $validation;

        $mailboxResolved = $this->resolveMailbox($fields['mailbox']);
        if ($mailboxResolved instanceof Response) {
            return $mailboxResolved;
        }
        $mailboxId = $mailboxResolved;

        $rawSourceFile = $this->validateRawSource();
        if ($rawSourceFile instanceof Response) {
            return $rawSourceFile;
        }

        $attachmentFiles = $this->collectAttachmentFiles();

        $uploadedFiles = [];
        $contentAttachments = [];
        $dibi = $this->db->getDibiConnection();
        $dibi->begin();

        try {
            $messageId = $this->insertIncomingMessage($fields, $mailboxId, $auth->userId);

            $rawResult = $this->attachments->upload(
                self::MAIL_TABLE_ID,
                $messageId,
                $rawSourceFile['name'],
                $rawSourceFile['tmp_name'],
                $auth->userId,
            );
            if (!$rawResult['success']) {
                throw new \RuntimeException($rawResult['error'] ?? 'Nelze uložit .eml soubor');
            }
            $uploadedFiles[] = $rawResult['data'];
            $rawAttachmentId = (int) $rawResult['data']['id'];

            $dibi->update(self::MAIL_TABLE, ['raw_source_attachment' => $rawAttachmentId])
                ->where('id = %i', $messageId)
                ->execute();

            foreach ($attachmentFiles as $att) {
                $attResult = $this->attachments->upload(
                    self::MAIL_TABLE_ID,
                    $messageId,
                    $att['name'],
                    $att['tmp_name'],
                    $auth->userId,
                );
                if (!$attResult['success']) {
                    throw new \RuntimeException($attResult['error'] ?? 'Nelze uložit přílohu');
                }
                $uploadedFiles[] = $attResult['data'];
                $contentAttachments[] = $attResult['data'];
            }

            $createdRow = $this->db->fetchRow('SELECT message_id FROM %n WHERE id = %i', self::MAIL_TABLE, $messageId);
            $messageCode = (string) ($createdRow['message_id'] ?? '');

            $responseData = [
                'ndx' => $messageId,
                'message_id' => $messageCode,
                'idempotent_replay' => false,
            ];

            if ($idempotencyKey !== null) {
                $payloadForReplay = ['success' => true, 'data' => $responseData];
                $this->idempotency->store(
                    $idempotencyKey,
                    $messageId,
                    (string) json_encode($payloadForReplay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
            }

            $dibi->commit();

            // Deterministický ISDOC import (tasks/mail-isdoc-import.md) —
            // až po commitu intake tx, nikdy nesmí shodit příjem pošty.
            $this->runIsdocImport($messageId, $contentAttachments);

            return Response::success($responseData, 201);
        } catch (\Throwable $e) {
            $dibi->rollback();
            $this->cleanupOrphanedFiles($uploadedFiles);

            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Deterministický import ISDOC příloh místo AI analýzy — běží po
     * commitu intake tx ve vlastní transakci (viz IsdocImportService).
     * Invariant: nikdy nesmí shodit příjem pošty; výsledek se do response
     * intake nepropaguje (mail-router ho nepotřebuje).
     *
     * @param list<array<string, mixed>> $contentAttachments Uploady příloh
     *        bez raw .eml souboru.
     */
    private function runIsdocImport(int $messageId, array $contentAttachments): void
    {
        if ($this->isdocImportFactory === null) {
            return;
        }

        try {
            $hasCandidate = false;
            foreach ($contentAttachments as $file) {
                if (is_array($file) && IsdocImportService::isPotentialIsdocAttachment($file)) {
                    $hasCandidate = true;
                    break;
                }
            }
            if (!$hasCandidate) {
                return;
            }

            ($this->isdocImportFactory)()->tryImport($messageId, $contentAttachments);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'MailController::receiveIncoming ISDOC import failed');
        }
    }

    /**
     * Endpoint `POST /_mail/import` — programové založení jedné importované
     * došlé zprávy (JSON, žádný .eml/přílohy). Vytváří přes Document path,
     * takže `beforeSave` vygeneruje `message_id` a znormalizuje `sender_email`.
     *
     * Na rozdíl od `/_mail/incoming` není omezen na systémového uživatele
     * `_mail_router` — běží pod libovolným api_key (typicky `_legacy_importer`),
     * konzistentně s `/_exchange/*`. Idempotenci řeší volající (LocalIdMap).
     *
     * Viz `tasks/mail-phase4-import-endpoint.md`.
     */
    public function importMessage(AuthContext $auth, Request $request): Response
    {
        if (!$auth->isAuthenticated || $auth->tokenType !== 'api_key') {
            return Response::error('UNAUTHORIZED', 'API key required', 401);
        }

        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
        }

        $mailboxResolved = $this->resolveMailbox(trim((string) ($body['mailbox'] ?? '')));
        if ($mailboxResolved instanceof Response) {
            return $mailboxResolved;
        }
        $mailboxId = $mailboxResolved;

        // received_at: ISO8601 → DB datetime; validitu dořeší Document::validate
        $receivedAtRaw = trim((string) ($body['received_at'] ?? ''));
        $receivedAt = $receivedAtRaw !== '' && strtotime($receivedAtRaw) !== false
            ? date('Y-m-d H:i:s', (int) strtotime($receivedAtRaw))
            : '';

        $subject = trim((string) ($body['subject'] ?? ''));
        if ($subject === '') {
            $subject = '(bez předmětu)';
        }

        // primary_type/source_type z těla mají přednost před defaulty v beforeSave,
        // proto je nastavujeme do $data před jeho voláním.
        $data = [
            'mailbox'             => $mailboxId,
            'subject'             => $subject,
            'sender_email'        => trim((string) ($body['sender_email'] ?? '')),
            'sender_name'         => self::nullIfEmpty($body['sender_name'] ?? null),
            'sender_person'       => isset($body['sender_person']) ? (int) $body['sender_person'] : null,
            'received_at'         => $receivedAt,
            'body_plain'          => self::nullIfEmpty($body['body_plain'] ?? null),
            'body_html'           => self::nullIfEmpty($body['body_html'] ?? null),
            'external_message_id' => self::nullIfEmpty($body['external_message_id'] ?? null),
            'in_reply_to'         => self::nullIfEmpty($body['in_reply_to'] ?? null),
            'reply_references'    => self::nullIfEmpty($body['reply_references'] ?? null),
            'target_table_id'     => self::nullIfEmpty($body['target_table_id'] ?? null),
            'target_row'          => isset($body['target_row']) ? (int) $body['target_row'] : null,
            'created_by'          => $auth->userId,
        ];
        if (!empty($body['primary_type'])) {
            $data['primary_type'] = (string) $body['primary_type'];
        }
        if (isset($body['source_type'])) {
            $data['source_type'] = (int) $body['source_type'];
        }

        // docState explicitně — beforeSave ho neřeší a DB default by byl 10.
        // Default 40 (Hotovo); volající pošle 10 pro nenavázané zprávy.
        $docState = isset($body['docState']) ? (int) $body['docState'] : 40;
        $data['docState']     = $docState;
        $data['docStateMain'] = $this->resolveIncomingMainState($docState);

        // analysis_state: explicitní hodnota z requestu má přednost; importy
        // rovnou v Hotovo (40) se neanalyzují (0). Jinak platí default
        // z beforeSave (fronta, pokud je AI dostupná).
        if (isset($body['analysis_state'])) {
            $data['analysis_state'] = (int) $body['analysis_state'];
        } elseif ($docState === 40) {
            $data['analysis_state'] = 0;
        }

        $doc = $this->documentRegistry->getDocument(self::MAIL_TABLE);
        $dibi = $this->db->getDibiConnection();
        $doc->setDb($dibi);

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0];
            return Response::error(
                'VALIDATION_ERROR',
                $first->message,
                422,
                [['field' => $first->column, 'code' => $first->code]],
            );
        }

        // Vygeneruje message_id, znormalizuje sender_email, dorovná default
        // primary_type/source_type (pokud je v $data nemáme).
        $doc->beforeSave($data);

        $dibi->insert(self::MAIL_TABLE, $data)->execute();
        $messageId = (int) $dibi->getInsertId();

        $createdRow = $this->db->fetchRow('SELECT message_id FROM %n WHERE id = %i', self::MAIL_TABLE, $messageId);

        return Response::success([
            'ndx'        => $messageId,
            'message_id' => (string) ($createdRow['message_id'] ?? ''),
        ], 201);
    }

    /**
     * Dopočítá `docStateMain` pro daný `docState` z `core.mail.docStatesIncoming`.
     * Bez compiled configu degraduje na pevnou mapu (hodnoty jsou stabilní
     * v docStatesIncoming.jsonc).
     */
    private function resolveIncomingMainState(int $docState): int
    {
        if ($this->config !== null) {
            return DocStateConfig::fromCfgItem(
                $this->config->cfgItem('core.mail.docStatesIncoming'),
            )->getMainState($docState);
        }

        return [10 => 1, 20 => 2, 40 => 3, 80 => 4, 90 => 5][$docState] ?? 1;
    }

    private function verifyAuth(AuthContext $auth): ?Response
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
        if ($user === null || ($user['login'] ?? '') !== MailRouterProvisioner::ROUTER_LOGIN) {
            return Response::error('FORBIDDEN', 'This endpoint is restricted to the mail-router system user', 403);
        }

        return null;
    }

    private function extractIdempotencyKey(Request $request): ?string
    {
        $key = $request->getHeader('X-Idempotency-Key');
        if ($key === null) {
            return null;
        }
        $trimmed = trim($key);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function replayResponse(string $responseBody): Response
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return Response::error('INTERNAL_ERROR', 'Corrupted idempotency record', 500);
        }
        $decoded['data']['idempotent_replay'] = true;
        return Response::success($decoded['data'], 201);
    }

    /**
     * @return array{
     *     mailbox: string,
     *     external_message_id: ?string,
     *     received_at: string,
     *     subject: string,
     *     sender_email: string,
     *     sender_name: ?string,
     *     body_plain: ?string,
     *     body_html: ?string,
     *     in_reply_to: ?string,
     *     reply_references: ?string,
     *     source_type: int,
     * }|Response
     */
    private function validateFormFields(): array|Response
    {
        $receivedAt = trim((string) ($_POST['received_at'] ?? ''));
        if ($receivedAt === '') {
            return Response::error('VALIDATION_ERROR', 'received_at je povinné', 422, [['field' => 'received_at']]);
        }
        if (strtotime($receivedAt) === false) {
            return Response::error('VALIDATION_ERROR', 'received_at není platné ISO8601 datum', 422, [['field' => 'received_at']]);
        }

        $senderEmail = trim((string) ($_POST['sender_email'] ?? ''));
        if ($senderEmail === '') {
            return Response::error('VALIDATION_ERROR', 'sender_email je povinné', 422, [['field' => 'sender_email']]);
        }
        if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('VALIDATION_ERROR', 'sender_email není platná adresa', 422, [['field' => 'sender_email']]);
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        if ($subject === '') {
            $subject = '(bez předmětu)';
        }

        return [
            'mailbox' => trim((string) ($_POST['mailbox'] ?? '')),
            'external_message_id' => self::nullIfEmpty($_POST['external_message_id'] ?? null),
            'received_at' => date('Y-m-d H:i:s', (int) strtotime($receivedAt)),
            'subject' => $subject,
            'sender_email' => $senderEmail,
            'sender_name' => self::nullIfEmpty($_POST['sender_name'] ?? null),
            'body_plain' => self::nullIfEmpty($_POST['body_plain'] ?? null),
            'body_html' => self::nullIfEmpty($_POST['body_html'] ?? null),
            'in_reply_to' => self::nullIfEmpty($_POST['in_reply_to'] ?? null),
            'reply_references' => self::nullIfEmpty($_POST['reply_references'] ?? null),
            'source_type' => isset($_POST['source_type']) ? (int) $_POST['source_type'] : 2,
        ];
    }

    private function resolveMailbox(string $mailboxCode): int|Response
    {
        if ($mailboxCode === '') {
            $row = $this->db->fetchRow(
                'SELECT id FROM core_mail_mailboxes WHERE is_default = %i LIMIT 1',
                1,
            );
            if ($row === null) {
                return Response::error(
                    'VALIDATION_ERROR',
                    'DS has no default mailbox configured',
                    422,
                    [['field' => 'mailbox']],
                );
            }
            return (int) $row['id'];
        }

        $row = $this->db->fetchRow(
            'SELECT id FROM core_mail_mailboxes WHERE mailbox_id = %s',
            $mailboxCode,
        );
        if ($row === null) {
            return Response::error(
                'VALIDATION_ERROR',
                "Schránka '{$mailboxCode}' neexistuje v tomto DS",
                422,
                [['field' => 'mailbox']],
            );
        }

        return (int) $row['id'];
    }

    /**
     * @return array{name: string, tmp_name: string}|Response
     */
    private function validateRawSource(): array|Response
    {
        if (!isset($_FILES['raw_source']) || $_FILES['raw_source']['error'] !== UPLOAD_ERR_OK) {
            return Response::error(
                'VALIDATION_ERROR',
                'raw_source (.eml soubor) je povinný',
                422,
                [['field' => 'raw_source']],
            );
        }
        return [
            'name' => (string) ($_FILES['raw_source']['name'] ?? 'message.eml'),
            'tmp_name' => (string) $_FILES['raw_source']['tmp_name'],
        ];
    }

    /**
     * @return list<array{name: string, tmp_name: string}>
     */
    private function collectAttachmentFiles(): array
    {
        if (!isset($_FILES['attachments'])) {
            return [];
        }
        $files = $_FILES['attachments'];
        if (!is_array($files['name'])) {
            return [];
        }

        $out = [];
        foreach ($files['name'] as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $out[] = [
                'name' => (string) $name,
                'tmp_name' => (string) $files['tmp_name'][$i],
            ];
        }
        return $out;
    }

    /**
     * Insert přes DocumentRegistry — respektuje `beforeSave` hook (generace
     * `message_id`, normalizace sender_email atd.).
     *
     * Používáme Dibi přímo v rámci aktivní tx místo TableGateway, protože
     * Gateway má vlastní `begin/commit` a my potřebujeme obalit také upload
     * attachmentů do jedné tx.
     */
    private function insertIncomingMessage(array $fields, int $mailboxId, int $authorId): int
    {
        $doc = $this->documentRegistry->getDocument(self::MAIL_TABLE);
        $dibi = $this->db->getDibiConnection();
        $doc->setDb($dibi);

        $data = [
            'mailbox' => $mailboxId,
            'subject' => $fields['subject'],
            'sender_email' => $fields['sender_email'],
            'sender_name' => $fields['sender_name'],
            'received_at' => $fields['received_at'],
            'external_message_id' => $fields['external_message_id'],
            'in_reply_to' => $fields['in_reply_to'],
            'reply_references' => $fields['reply_references'],
            'body_plain' => $fields['body_plain'],
            'body_html' => $fields['body_html'],
            'source_type' => $fields['source_type'],
            'created_by' => $authorId,
        ];

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0];
            throw new \RuntimeException(
                "Validation failed on {$first->column}: {$first->message}",
            );
        }

        $doc->beforeSave($data);

        $dibi->insert(self::MAIL_TABLE, $data)->execute();
        return (int) $dibi->getInsertId();
    }

    /**
     * Odstraní soubory uložené na disk během neúspěšné transakce.
     * DB záznamy rollback odstraní, orphan files zůstanou.
     *
     * @param list<array<string, mixed>> $uploadedFiles
     */
    private function cleanupOrphanedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $att) {
            $filePath = (string) ($att['file_path'] ?? '');
            $fileName = (string) ($att['file_name'] ?? '');
            if ($filePath === '' || $fileName === '') {
                continue;
            }
            $fullPath = $this->dsPath . '/att/' . $filePath . '/' . $fileName;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s !== '' ? $s : null;
    }
}
