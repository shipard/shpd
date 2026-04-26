<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `core_mail_extracted_documents`.
 *
 * Hlavní zodpovědnost: auto-transition zprávy 30 → 40.
 * Když se status extracted documentu mění na `applied` (40), `rejected` (50)
 * nebo `superseded` (60) a žádný sourozenec téže zprávy už není ve stavu
 * `ready_to_apply` (10), `pending_review` (20) ani `low_confidence` (30),
 * automaticky přepneme zprávu z `docState=30` (Analyzovaná) na `docState=40`
 * (Zpracovaná). Stav `ai_failed` (70) přechodu nebrání — admin se může
 * rozhodnout uzavřít zprávu i s neúspěšnou extrakcí.
 *
 * Spec: tasks/mail-phase3a.md §10 rozhodnutí 4.
 */
class ExtractedDocumentDocument extends Document
{
    // Status hodnoty (musí odpovídat config/extractedDocStates.jsonc)
    public const STATUS_READY_TO_APPLY = 10;
    public const STATUS_PENDING_REVIEW = 20;
    public const STATUS_LOW_CONFIDENCE = 30;
    public const STATUS_APPLIED        = 40;
    public const STATUS_REJECTED       = 50;
    public const STATUS_SUPERSEDED     = 60;
    public const STATUS_AI_FAILED      = 70;

    /** Statusy, které stále drží zprávu v "Analyzovaná" (30). */
    private const PENDING_STATUSES = [
        self::STATUS_READY_TO_APPLY,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_LOW_CONFIDENCE,
    ];

    /** Statusy, které spouštějí kontrolu auto-transition. */
    private const RESOLVED_STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_REJECTED,
        self::STATUS_SUPERSEDED,
    ];

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const EXTRACTED_TABLE = 'core_mail_extracted_documents';
    private const DOC_STATE_ANALYZED = 30;
    private const DOC_STATE_PROCESSED = 40;
    private const DOC_STATE_MAIN_PROCESSED = 4;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $message = isset($data['message']) ? (int) $data['message'] : 0;
        if ($message <= 0) {
            $result->addError('message', 'Zpráva je povinná', 'required');
        }

        $analysis = isset($data['analysis']) ? (int) $data['analysis'] : 0;
        if ($analysis <= 0) {
            $result->addError('analysis', 'Běh analýzy je povinný', 'required');
        }

        if (empty(trim((string) ($data['doc_type'] ?? '')))) {
            $result->addError('doc_type', 'Typ dokumentu je povinný', 'required');
        }

        $status = isset($data['status']) ? (int) $data['status'] : 0;
        if ($status === self::STATUS_REJECTED && empty(trim((string) ($data['rejected_reason'] ?? '')))) {
            $result->addError(
                'rejected_reason',
                'Důvod zamítnutí je povinný',
                'required',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        if ($isNew) {
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
            if (!isset($data['status'])) {
                $data['status'] = self::STATUS_PENDING_REVIEW;
            }
        }

        // Audit pole pro applied / rejected — pokud volající nezadal jinak.
        $status = isset($data['status']) ? (int) $data['status'] : null;
        if ($status === self::STATUS_APPLIED && empty($data['applied_at'])) {
            $data['applied_at'] = $now;
        }
    }

    /**
     * Auto-transition běží jako afterPersist (uvnitř save transakce, před
     * commitem) — když UPDATE zprávy selže, celý save extracted documentu
     * se rolluje. Spec §10 rozhodnutí 4: "Přechod proběhne ve stejné
     * transakci jako save (atomic)".
     */
    public function afterPersist(array $data): void
    {
        if ($this->db === null) {
            return;
        }

        $status = isset($data['status']) ? (int) $data['status'] : null;
        if ($status === null || !in_array($status, self::RESOLVED_STATUSES, true)) {
            return;
        }

        $messageId = isset($data['message']) ? (int) $data['message'] : 0;
        if ($messageId <= 0) {
            return;
        }

        $this->maybeTransitionMessage($messageId);
    }

    /**
     * Pokud zpráva je v `docState=30` (Analyzovaná) a žádný sourozenec není
     * v pending statusu, přepne ji na `docState=40` (Zpracovaná).
     * Volá se v rámci téže transakce jako save (TableGateway commitne na konci).
     */
    protected function maybeTransitionMessage(int $messageId): void
    {
        if (!$this->messageIsAnalyzed($messageId)) {
            return;
        }

        if ($this->countPendingSiblings($messageId) > 0) {
            return;
        }

        $this->markMessageProcessed($messageId);
    }

    protected function messageIsAnalyzed(int $messageId): bool
    {
        $row = $this->db->fetch(
            'SELECT %n FROM %n WHERE %n = %i',
            'docState',
            self::MESSAGES_TABLE,
            'id',
            $messageId,
        );

        if ($row === null) {
            return false;
        }
        return (int) ((array) $row)['docState'] === self::DOC_STATE_ANALYZED;
    }

    protected function countPendingSiblings(int $messageId): int
    {
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE %n = %i AND %n IN %in',
            self::EXTRACTED_TABLE,
            'message',
            $messageId,
            'status',
            self::PENDING_STATUSES,
        );
    }

    protected function markMessageProcessed(int $messageId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->update(self::MESSAGES_TABLE, [
            'docState' => self::DOC_STATE_PROCESSED,
            'docStateMain' => self::DOC_STATE_MAIN_PROCESSED,
            'modified' => $now,
        ])->where('%n = %i', 'id', $messageId)->execute();
    }
}
