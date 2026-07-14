<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Mail\ExtractedDocTypes;
use Shipard\Module\Core\Mail\ExtractedTargetApplier;
use Shipard\Module\Core\Mail\TargetApplyResult;
use Shipard\Module\Core\Mail\TargetUnapplyResult;

/**
 * Target applier Spisovny (design §7.3) — vytváří dokument
 * `base_registry_documents` z canonical extrakce `shpd.registry.document.v1`.
 *
 * - Dokument vzniká rovnou v **docState 40 (Zařazeno)** — jednoklik je
 *   jednoklik, vratnost zajišťuje unapply → Koš.
 * - `metadata` = `kindFields` 1:1; promoted sloupce (`ref_number`,
 *   `valid_from`, `valid_to`) doplní `RegistryDocumentDocument::beforeSave`
 *   přes `docKinds.promote` — applier je nenastavuje ručně.
 * - Partner (§7.6): PartyResolver match-only → e-mail odesílatele
 *   ({@see PartnerEmailMatcher}) → NULL. Žádné auto-zakládání osob.
 * - Šanon (§7.5): historie (nejčastější šanon partner+druh) →
 *   `binderSuggestion` case-insensitive → NULL. Nikdy nezakládá šanon.
 * - `target_table_id`/`target_row_ndx` zapisuje na extracted řádek uvnitř
 *   své transakce (symetrie s `DocumentApplier::writeLineageTargets` —
 *   recovery přes `completeApplied` na tom stojí).
 * - Kopie příloh (D8) + best-effort `extracted_text` po commitu.
 */
final class RegistryApplier implements ExtractedTargetApplier
{
    private const EXTRACTED_TABLE = 'core_mail_extracted_documents';
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const ATTACHMENTS_TABLE = 'core_attachments_files';
    private const REGISTRY_TABLE = 'base_registry_documents';
    private const BINDERS_TABLE = 'base_registry_binders';

    private const MAIL_TABLE_ID = 303;
    private const REGISTRY_TABLE_ID = 428;

    /** Živé stavy archivační sady (viewGroup active: Koncept/V pořádku/V opravě). */
    private const LIVE_STATES = [10, 40, 80];

    private const DOC_STATE_FILED = 40;
    private const DOC_STATE_TRASH = 90;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DocumentRegistry $documentRegistry,
        private readonly AttachmentService $attachments,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?PartyResolver $partyResolver = null,
    ) {}

    public function apply(array $canonical, array $extractedRow, ?int $userId): TargetApplyResult
    {
        $extractedNdx = (int) ($extractedRow['id'] ?? 0);
        $messageNdx = (int) ($extractedRow['message'] ?? 0);
        $docType = (string) ($extractedRow['doc_type'] ?? '');

        if (trim((string) ($canonical['title'] ?? '')) === '') {
            return TargetApplyResult::error(
                'VALIDATION_ERROR', 'Registry canonical is missing required title', 422,
            );
        }

        $message = $messageNdx > 0
            ? $this->db->fetchRow('SELECT * FROM %n WHERE id = %i', self::MESSAGES_TABLE, $messageNdx)
            : null;

        $docKind = ExtractedDocTypes::docKindFor($this->config, $docType);
        if ($docKind === null) {
            // Misconfig (registry target bez docKind) — dokument vznikne jako
            // 'other', metadata zůstanou 1:1, jen se nepromotují sloupce.
            ErrorLogger::warn('RegistryApplier: doc_type has no docKind in cfg, falling back to other', [
                'docType' => $docType, 'extractedNdx' => $extractedNdx,
            ]);
            $docKind = 'other';
        }

        $party = is_array($canonical['party'] ?? null) ? $canonical['party'] : null;
        $partner = $this->resolvePartner($party, (string) ($message['sender_email'] ?? ''));
        $binder = $this->suggestBinder($partner, $docKind, $canonical['binderSuggestion'] ?? null);

        $data = $this->buildDocumentData($canonical, $docKind, $partner, $binder, $messageNdx, $extractedNdx, $userId);

        $dibi = $this->db->getDibiConnection();
        $copiedFiles = [];

        $dibi->begin();
        try {
            $documentId = $this->insertDocument($data);
            $this->copyAttachments($extractedRow, $message, $documentId, $userId, $copiedFiles);

            // Lineage na extracted řádek uvnitř transakce — symetrie s docs
            // cestou (DocumentApplier::writeLineageTargets).
            $dibi->update(self::EXTRACTED_TABLE, [
                'target_table_id' => self::REGISTRY_TABLE,
                'target_row_ndx'  => $documentId,
            ])->where('id = %i', $extractedNdx)->execute();

            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            $this->cleanupCopiedFiles($copiedFiles);
            return TargetApplyResult::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        // Best-effort fulltext z kopií — po commitu, selhání apply neblokuje.
        new ExtractedTextFiller($this->db, $this->attachments)->fill($documentId);

        return TargetApplyResult::ok($documentId);
    }

    /**
     * Guard: dokument stále ve stavu 40 a nezměněný od apply
     * (`modified <= applied_at`; `extracted_text` fill jde mimo hooky,
     * `modified` nebumpuje) — jinak DOC_ADVANCED a řeší uživatel ručně.
     * Úklid: dokument → Koš (90) přes Document hooky, přílohy se nemažou
     * (soft-delete je vratný).
     */
    public function unapply(array $extractedRow): TargetUnapplyResult
    {
        $targetDocId = (int) ($extractedRow['target_row_ndx'] ?? 0);

        $doc = $this->db->fetchRow('SELECT * FROM %n WHERE id = %i', self::REGISTRY_TABLE, $targetDocId);
        if ($doc === null) {
            return TargetUnapplyResult::error('DOC_ADVANCED', 'Target document no longer exists', 409);
        }
        if ((int) ($doc['docState'] ?? 0) !== self::DOC_STATE_FILED) {
            return TargetUnapplyResult::error('DOC_ADVANCED', 'Target document is no longer in filed state', 409);
        }

        $modified = $this->toTimestamp($doc['modified'] ?? null);
        $appliedAt = $this->toTimestamp($extractedRow['applied_at'] ?? null);
        if ($modified !== null && ($appliedAt === null || $modified > $appliedAt)) {
            return TargetUnapplyResult::error('DOC_ADVANCED', 'Target document was modified since apply', 409);
        }

        try {
            $data = $doc;
            $data['docState'] = self::DOC_STATE_TRASH;
            $data['docStateMain'] = $this->resolveArchiveMainState(self::DOC_STATE_TRASH);
            $this->saveDocumentViaHooks($data, $doc);
        } catch (\Throwable $e) {
            return TargetUnapplyResult::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }

        return TargetUnapplyResult::ok($targetDocId);
    }

    // -------------------------------------------------------------------------
    // Mapping + resolve (public kvůli unit testům — čisté/DB-mockovatelné)
    // -------------------------------------------------------------------------

    /**
     * Mapping canonical → řádek dokumentu (design §7.3 krok 1). Chybějící
     * `kindFields` = prázdná metadata, žádný fail.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function buildDocumentData(
        array $canonical,
        string $docKind,
        ?int $partner,
        ?int $binder,
        int $messageNdx,
        int $extractedNdx,
        ?int $userId,
    ): array {
        $kindFields = is_array($canonical['kindFields'] ?? null) ? $canonical['kindFields'] : [];
        $summary = trim((string) ($canonical['summary'] ?? ''));

        return [
            'title'          => trim((string) $canonical['title']),
            'doc_kind'       => $docKind,
            'binder'         => $binder,
            'partner'        => $partner,
            'metadata'       => $kindFields,
            'ai_summary'     => $summary !== '' ? $summary : null,
            'source_kind'    => 'mail',
            'source_message' => $messageNdx > 0 ? $messageNdx : null,
            'extracted_doc'  => $extractedNdx,
            'docState'       => self::DOC_STATE_FILED,
            'docStateMain'   => $this->resolveArchiveMainState(self::DOC_STATE_FILED),
            'created_by'     => $userId,
        ];
    }

    /**
     * Partner resolve (§7.6): PartyResolver (companyId/name) — počítá se
     * **jen** `Matched`; cokoli jiného (ambiguous, canCreate, notFound,
     * výjimka) → fallback match e-mailu odesílatele → NULL.
     *
     * @param array<string, mixed>|null $party
     */
    public function resolvePartner(?array $party, string $senderEmail): ?int
    {
        if ($party !== null && $this->partyResolver !== null) {
            try {
                $result = $this->partyResolver->resolve([
                    'companyId' => $party['companyId'] ?? null,
                    'name'      => $party['name'] ?? null,
                ]);
                if ($result->status === ResolveStatus::Matched && $result->matchedId !== null) {
                    return $result->matchedId;
                }
            } catch (\Throwable $e) {
                ErrorLogger::warn('RegistryApplier: party resolve failed, falling back to e-mail match', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return PartnerEmailMatcher::match($this->db, $senderEmail);
    }

    /**
     * Návrh šanonu (§7.5), bez LLM: (1) nejčastější šanon živých dokumentů
     * téhož partnera a druhu; (2) `binderSuggestion` match na živé šanony
     * (case-insensitive přes utf8mb4_czech_ci kolaci); (3) NULL.
     * **Nikdy nezakládá šanon.**
     */
    public function suggestBinder(?int $partner, string $docKind, mixed $binderSuggestion): ?int
    {
        if ($partner !== null) {
            $row = $this->db->fetchRow(
                'SELECT d.`binder`, COUNT(*) AS cnt FROM %n d'
                . ' JOIN %n b ON b.`id` = d.`binder`'
                . ' WHERE d.`partner` = %i AND d.`doc_kind` = %s'
                . '   AND d.`docState` IN (10, 40, 80) AND b.`docState` IN (10, 40, 80)'
                . ' GROUP BY d.`binder`'
                . ' ORDER BY cnt DESC, d.`binder` DESC'
                . ' LIMIT 1',
                self::REGISTRY_TABLE, self::BINDERS_TABLE, $partner, $docKind,
            );
            if ($row !== null) {
                return (int) $row['binder'];
            }
        }

        $name = trim((string) ($binderSuggestion ?? ''));
        if ($name !== '') {
            $row = $this->db->fetchRow(
                'SELECT `id` FROM %n'
                . ' WHERE `name` = %s AND `docState` IN (10, 40, 80)'
                . ' ORDER BY `docStateMain` ASC, `order_pos` ASC, `id` ASC'
                . ' LIMIT 1',
                self::BINDERS_TABLE, $name,
            );
            if ($row !== null) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * INSERT dokumentu přes Document hooky uvnitř vnější transakce
     * (vzor FileFromMessageService::insertRegistryDocument). Vznik rovnou
     * ve 40 — TableGateway/hooky přechody na insertu nevalidují.
     *
     * @param array<string, mixed> $data
     */
    private function insertDocument(array $data): int
    {
        $dibi = $this->db->getDibiConnection();
        $doc = $this->documentRegistry->getDocument(self::REGISTRY_TABLE, $data);
        $doc->setDb($dibi);
        if ($this->config !== null) {
            $doc->setConfig($this->config);
        }

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0] ?? null;
            throw new \RuntimeException(
                $first !== null
                    ? "Validation failed on {$first->column}: {$first->message}"
                    : 'Validation failed',
            );
        }

        $doc->beforeSave($data);

        $dibi->insert(self::REGISTRY_TABLE, $data)->execute();
        $id = (int) $dibi->getInsertId();

        $doc->afterPersist($data + ['id' => $id]);

        return $id;
    }

    /**
     * UPDATE dokumentu přes Document hooky (vzor
     * FileFromMessageService::updateMessage) — žádný přímý stavový UPDATE.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $original
     */
    private function saveDocumentViaHooks(array $data, array $original): void
    {
        $dibi = $this->db->getDibiConnection();
        $doc = $this->documentRegistry->getDocument(self::REGISTRY_TABLE, $data);
        $doc->setDb($dibi);
        if ($this->config !== null) {
            $doc->setConfig($this->config);
        }

        $validation = $doc->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0] ?? null;
            throw new \RuntimeException(
                $first !== null
                    ? "Validation failed on {$first->column}: {$first->message}"
                    : 'Validation failed',
            );
        }

        $doc->beforeSave($data, $original);

        $writable = $data;
        unset($writable['id']);
        $dibi->update(self::REGISTRY_TABLE, $writable)
            ->where('id = %i', (int) $original['id'])
            ->execute();

        $doc->afterPersist($data);
    }

    /**
     * Kopie příloh (D8): primárně dle `source_attachments` extracted řádku;
     * fallback všechny obsahové přílohy zprávy (bez raw .eml, bez smazaných
     * — stejný výběr jako ruční cesta Fáze 1).
     *
     * @param array<string, mixed>      $extractedRow
     * @param array<string, mixed>|null $message
     * @param list<array{file_path: string, file_name: string}> $copiedFiles
     */
    private function copyAttachments(
        array $extractedRow,
        ?array $message,
        int $documentId,
        ?int $userId,
        array &$copiedFiles,
    ): void {
        $attachmentIds = $this->parseSourceAttachments((string) ($extractedRow['source_attachments'] ?? ''));
        if ($attachmentIds === [] && $message !== null) {
            $attachmentIds = $this->contentAttachmentIds($message);
        }

        foreach ($attachmentIds as $attId) {
            $result = $this->attachments->copyTo($attId, self::REGISTRY_TABLE_ID, $documentId, $userId);
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
    }

    /** @return list<int> */
    private function parseSourceAttachments(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Obsahové přílohy zprávy — bez raw .eml, bez smazaných (výběr shodný
     * s IncomingMessagesViewer::fetchContentAttachments).
     *
     * @param array<string, mixed> $message
     * @return list<int>
     */
    private function contentAttachmentIds(array $message): array
    {
        $rawId = isset($message['raw_source_attachment']) && $message['raw_source_attachment'] !== null
            ? (int) $message['raw_source_attachment']
            : null;

        $sql = 'SELECT `id` FROM %n'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0';
        $params = [self::ATTACHMENTS_TABLE, self::MAIL_TABLE_ID, (int) $message['id']];
        if ($rawId !== null) {
            $sql .= ' AND `id` != %i';
            $params[] = $rawId;
        }
        $sql .= ' ORDER BY `att_order` ASC, `name` ASC';

        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->db->fetchAll($sql, ...$params),
        );
    }

    /**
     * Úklid fyzických kopií po rollbacku (vzor
     * FileFromMessageService::cleanupCopiedFiles).
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

    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value) && trim($value) !== '') {
            $ts = strtotime($value);
            return $ts !== false ? $ts : null;
        }
        return null;
    }
}
