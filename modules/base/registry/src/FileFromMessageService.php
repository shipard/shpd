<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Ruční zařazení došlé zprávy do Spisovny (design §6.4).
 *
 * Vytvoří Koncept dokumentu (title = subject, doc_kind 'other',
 * source_kind 'mail', partner dle jednoznačného matche sender_email),
 * zkopíruje obsahové přílohy zprávy (D8 — kopie, ne přesun) a zprávu
 * označí polymorfní vazbou target_* + přechodem 10/20 → 40 (Hotovo).
 *
 * Atomicita: všechny DB kroky v jedné transakci; zkopírované soubory se
 * při rollbacku unlinknou (vzor MailController::receiveIncoming).
 * Přechod zprávy jde přes Document hooky (validate → beforeSave →
 * update → afterPersist), žádný přímý stavový UPDATE.
 */
final class FileFromMessageService
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ATTACHMENTS_TABLE = 'core_attachments_files';
    private const REGISTRY_TABLE = 'base_registry_documents';

    private const MAIL_TABLE_ID = 303;
    private const REGISTRY_TABLE_ID = 428;

    /** Stavy zprávy, ze kterých zařazení přepíná na 40 (Hotovo). */
    private const MESSAGE_TRANSITION_STATES = [10, 20];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DocumentRegistry $documentRegistry,
        private readonly AttachmentService $attachments,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     id?: int,
     *     warning?: array{code: string, message: string, existing_document_ndx: int},
     *     errorCode?: string,
     *     errorMessage?: string,
     *     statusCode?: int,
     * }
     */
    public function fileFromMessage(int $messageNdx, ?int $userId): array
    {
        $message = $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageNdx,
        );
        if ($message === null) {
            return [
                'ok' => false,
                'errorCode' => 'NOT_FOUND',
                'errorMessage' => "Message {$messageNdx} not found",
                'statusCode' => 404,
            ];
        }
        if ((int) $message['docState'] === 90) {
            return [
                'ok' => false,
                'errorCode' => 'INVALID_STATE',
                'errorMessage' => 'Message is in trash',
                'statusCode' => 409,
            ];
        }

        $partner = $this->matchPartner((string) ($message['sender_email'] ?? ''));

        $dibi = $this->db->getDibiConnection();
        $copiedFiles = [];

        $dibi->begin();
        try {
            $documentId = $this->insertRegistryDocument($message, $partner, $userId);

            $warning = $this->copyContentAttachments($message, $documentId, $userId, $copiedFiles);

            $this->updateMessage($message, $documentId);

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            $this->cleanupCopiedFiles($copiedFiles);
            return [
                'ok' => false,
                'errorCode' => 'INTERNAL_ERROR',
                'errorMessage' => $e->getMessage(),
                'statusCode' => 500,
            ];
        }

        // Best-effort fulltext z kopií příloh — až po commitu, selhání
        // zařazení neblokuje (viz ExtractedTextFiller).
        new ExtractedTextFiller($this->db, $this->attachments)->fill($documentId);

        $result = ['ok' => true, 'id' => $documentId];
        if ($warning !== null) {
            $result['warning'] = $warning;
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Match odesílatele na živou osobu (docState 10/40/80) dle e-mailu —
     * přes primární e-mail osoby i kontakty. Použije se **jen při právě
     * jednom** distinct matchi; jinak null (žádné hádání).
     */
    private function matchPartner(string $senderEmail): ?int
    {
        $email = strtolower(trim($senderEmail));
        if ($email === '') {
            return null;
        }

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT p.`id`'
            . ' FROM `base_persons_persons` p'
            . ' LEFT JOIN `base_persons_contacts` c'
            . '   ON c.`person` = p.`id` AND c.`docState` != 90'
            . ' WHERE (LOWER(p.`email`) = %s OR LOWER(c.`email`) = %s)'
            . '   AND p.`docState` IN (10, 40, 80)'
            . ' LIMIT 2',
            $email,
            $email,
        );

        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }

    /**
     * INSERT Konceptu dokumentu přes Document hooky uvnitř vnější transakce
     * (vzor MailController::insertIncomingMessage).
     */
    private function insertRegistryDocument(array $message, ?int $partner, ?int $userId): int
    {
        $subject = trim((string) ($message['subject'] ?? ''));

        $data = [
            'title'          => $subject !== '' ? $subject : '(bez předmětu)',
            'doc_kind'       => 'other',
            'source_kind'    => 'mail',
            'source_message' => (int) $message['id'],
            'partner'        => $partner,
            'docState'       => 10,
            'docStateMain'   => $this->resolveArchiveMainState(10),
            'created_by'     => $userId,
        ];

        $dibi = $this->db->getDibiConnection();
        $doc = $this->documentRegistry->getDocument(self::REGISTRY_TABLE, $data);
        $doc->setDb($dibi);
        if ($this->config !== null) {
            $doc->setConfig($this->config);
        }

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0];
            throw new \RuntimeException(
                "Validation failed on {$first->column}: {$first->message}",
            );
        }

        $doc->beforeSave($data);

        $dibi->insert(self::REGISTRY_TABLE, $data)->execute();
        $id = (int) $dibi->getInsertId();

        $doc->afterPersist($data + ['id' => $id]);

        return $id;
    }

    /**
     * Kopie obsahových příloh zprávy (výběr shodný s
     * IncomingMessagesViewer::fetchContentAttachments — bez raw .eml, bez
     * smazaných) na nový dokument. Vrací warning DUPLICATE_IN_REGISTRY,
     * má-li jiný živý dokument Spisovny přílohu se stejným checksumem.
     *
     * @param list<array{file_path: string, file_name: string}> $copiedFiles
     * @return array{code: string, message: string, existing_document_ndx: int}|null
     */
    private function copyContentAttachments(
        array $message,
        int $documentId,
        ?int $userId,
        array &$copiedFiles,
    ): ?array {
        $rawId = isset($message['raw_source_attachment']) && $message['raw_source_attachment'] !== null
            ? (int) $message['raw_source_attachment']
            : null;

        $sql = 'SELECT `id`, `checksum` FROM %n'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0';
        $params = [self::ATTACHMENTS_TABLE, self::MAIL_TABLE_ID, (int) $message['id']];
        if ($rawId !== null) {
            $sql .= ' AND `id` != %i';
            $params[] = $rawId;
        }
        $sql .= ' ORDER BY `att_order` ASC, `name` ASC';

        $attachments = $this->db->fetchAll($sql, ...$params);

        $warning = null;
        foreach ($attachments as $att) {
            $duplicateNdx = $this->findRegistryDuplicate((string) $att['checksum'], $documentId);
            if ($duplicateNdx !== null && $warning === null) {
                $warning = [
                    'code' => 'DUPLICATE_IN_REGISTRY',
                    'message' => 'Dokument se shodnou přílohou už ve Spisovně existuje',
                    'existing_document_ndx' => $duplicateNdx,
                ];
            }

            $result = $this->attachments->copyTo(
                (int) $att['id'],
                self::REGISTRY_TABLE_ID,
                $documentId,
                $userId,
            );
            if (!($result['success'] ?? false)) {
                throw new \RuntimeException(
                    'Attachment copy failed: ' . ($result['error'] ?? 'unknown error'),
                );
            }
            $copiedFiles[] = [
                'file_path' => (string) $result['data']['file_path'],
                'file_name' => (string) $result['data']['file_name'],
            ];
        }

        return $warning;
    }

    /**
     * Existuje k jinému živému dokumentu Spisovny příloha se stejným
     * checksumem? Vrací ndx existujícího dokumentu, jinak null.
     */
    private function findRegistryDuplicate(string $checksum, int $excludeDocumentId): ?int
    {
        if ($checksum === '') {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT f.`record_id` FROM %n f'
            . ' JOIN %n d ON d.`id` = f.`record_id`'
            . ' WHERE f.`table_id` = %i AND f.`checksum` = %s'
            . '   AND f.`is_deleted` = 0 AND f.`record_id` != %i'
            . '   AND d.`docState` != 90'
            . ' LIMIT 1',
            self::ATTACHMENTS_TABLE,
            self::REGISTRY_TABLE,
            self::REGISTRY_TABLE_ID,
            $checksum,
            $excludeDocumentId,
        );
        return $row !== null ? (int) $row['record_id'] : null;
    }

    /**
     * Polymorfní vazba target_* (audit, vždy) + přechod 10/20 → 40 (Hotovo)
     * přes Document hooky (žádný přímý stavový UPDATE) — design §11 bod 1:
     * hned při vzniku Konceptu.
     */
    private function updateMessage(array $message, int $documentId): void
    {
        $dibi = $this->db->getDibiConnection();

        $data = (array) $message;
        $data['target_table_id'] = self::REGISTRY_TABLE;
        $data['target_row'] = $documentId;

        if (in_array((int) $message['docState'], self::MESSAGE_TRANSITION_STATES, true)) {
            $data['docState'] = 40;
            $data['docStateMain'] = $this->resolveIncomingMainState(40);
        }

        $doc = $this->documentRegistry->getDocument(self::MESSAGES_TABLE, $data);
        $doc->setDb($dibi);
        if ($this->config !== null) {
            $doc->setConfig($this->config);
        }

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0];
            throw new \RuntimeException(
                "Message validation failed on {$first->column}: {$first->message}",
            );
        }

        $doc->beforeSave($data, (array) $message);

        $writable = $data;
        unset($writable['id']);
        $dibi->update(self::MESSAGES_TABLE, $writable)
            ->where('id = %i', (int) $message['id'])
            ->execute();

        $doc->afterPersist($data);
    }

    /** docStateMain pro `core.system.docStatesArchive` s fallback mapou. */
    private function resolveArchiveMainState(int $docState): int
    {
        if ($this->config !== null) {
            return DocStateConfig::fromCfgItem(
                $this->config->cfgItem('core.system.docStatesArchive'),
            )->getMainState($docState);
        }
        return [10 => 1, 80 => 2, 40 => 3, 70 => 4, 90 => 5][$docState] ?? 1;
    }

    /** docStateMain pro `core.mail.docStatesIncoming` s fallback mapou. */
    private function resolveIncomingMainState(int $docState): int
    {
        if ($this->config !== null) {
            return DocStateConfig::fromCfgItem(
                $this->config->cfgItem('core.mail.docStatesIncoming'),
            )->getMainState($docState);
        }
        return [10 => 1, 20 => 2, 40 => 3, 80 => 4, 90 => 5][$docState] ?? 1;
    }

    /**
     * Úklid fyzických kopií po rollbacku — DB řádky vzal rollback, soubory
     * na disku by zůstaly orphan (vzor MailController::cleanupOrphanedFiles).
     *
     * @param list<array{file_path: string, file_name: string}> $copiedFiles
     */
    private function cleanupCopiedFiles(array $copiedFiles): void
    {
        foreach ($copiedFiles as $file) {
            $path = $this->attachments->getFilePath([
                'file_path' => $file['file_path'],
                'file_name' => $file['file_name'],
            ]);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
