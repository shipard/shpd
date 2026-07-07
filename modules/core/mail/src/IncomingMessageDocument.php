<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `core_mail_incoming_messages`.
 *
 * Zodpovědnosti:
 *   - validace povinných polí (mailbox, subject, sender_email, received_at)
 *   - read-only zámek během aktivní analýzy (analysis_state = 20)
 *   - generování `message_id` ve tvaru `MSG-YYYYMMDD-NNNN` u nových záznamů
 *   - nastavení `source_type = 1` (manual) u nových záznamů vznikajících přes UI
 *   - default `analysis_state` (fronta AI analýzy) u nových záznamů
 *   - normalizace `sender_email` (trim, lowercase)
 *   - cascade delete analýz a attachmentů při smazání zprávy
 */
class IncomingMessageDocument extends Document
{
    private const ID_PREFIX = 'MSG-';
    private const MAIL_TABLE_ID = 303;

    /** analysis_state hodnoty (core.mail.analysisStates). */
    private const ANALYSIS_NONE = 0;
    private const ANALYSIS_QUEUED = 10;
    private const ANALYSIS_ANALYZING = 20;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        // Read-only zámek: během aktivního claimu analyzeru (analysis_state=20)
        // se zpráva nesmí ukládat — výsledek analýzy by přepsal souběžnou
        // editaci. Váže se na analysis_state, ne docState (workflow je volný).
        if (!empty($data['id']) && $this->db !== null) {
            $row = $this->db->fetch(
                'SELECT %n FROM %n WHERE %n = %i',
                'analysis_state',
                'core_mail_incoming_messages',
                'id',
                (int) $data['id'],
            );
            $current = $row !== null ? (int) ((array) $row)['analysis_state'] : null;
            if ($current === self::ANALYSIS_ANALYZING) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Zpráva se právě analyzuje, počkejte na dokončení.',
                    'analysis_in_progress',
                );
                return $result;
            }
        }

        $mailbox = isset($data['mailbox']) ? (int) $data['mailbox'] : 0;
        if ($mailbox <= 0) {
            $result->addError('mailbox', 'Schránka je povinná', 'required');
        }

        if (empty(trim((string) ($data['subject'] ?? '')))) {
            $result->addError('subject', 'Předmět je povinný', 'required');
        }

        $senderEmail = trim((string) ($data['sender_email'] ?? ''));
        if ($senderEmail === '') {
            $result->addError('sender_email', 'E-mail odesílatele je povinný', 'required');
        } elseif (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            $result->addError('sender_email', 'E-mailová adresa není ve správném formátu', 'invalid_format');
        }

        if (empty($data['received_at'])) {
            $result->addError('received_at', 'Datum doručení je povinné', 'required');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $isNew = empty($data['id']);
        $now = date('Y-m-d H:i:s');
        $mailbox = isset($data['mailbox']) ? (int) $data['mailbox'] : null;

        // Normalizace sender_email
        if (isset($data['sender_email'])) {
            $data['sender_email'] = strtolower(trim((string) $data['sender_email']));
        }

        if ($isNew) {
            // Default source_type = 1 (manual)
            if (empty($data['source_type'])) {
                $data['source_type'] = 1;
            }

            // received_at default = now (bezpečnostní síť; Form by měl dodat hodnotu)
            if (empty($data['received_at'])) {
                $data['received_at'] = $now;
            }

            // primary_type default — nejprve zkusíme default schránky, fallback 'other'
            if (empty($data['primary_type'])) {
                $data['primary_type'] = $this->resolveDefaultPrimaryType($mailbox);
            }

            // analysis_state default — do fronty AI analýzy, pokud je analýza
            // povolená (ai_analysis_enabled NOT FALSE) a dostupná (existuje
            // aktivní AI profil). Jinak 0 (Bez analýzy). Volající může
            // hodnotu předvyplnit (import, seed) — tu nepřepisujeme.
            if (!isset($data['analysis_state'])) {
                $data['analysis_state'] = $this->resolveInitialAnalysisState($data);
            }

            // Audit pole
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
            $data['modified'] = $now;

            // message_id generujeme pouze pokud chybí
            if (empty($data['message_id']) && $this->db !== null) {
                $data['message_id'] = $this->generateMessageId($data['received_at']);
            }
        } else {
            $data['modified'] = $now;

            // Ruční změna primárního typu → zdroj 'user' (AI klasifikace ji
            // pak nikdy nepřepíše). Pipeline zapisuje primary_type přímým
            // UPDATE mimo Document; sem přichází jen UI/API save. Explicitně
            // poslaný primary_type_source má přednost.
            if (!isset($data['primary_type_source'])
                && $originalData !== null
                && isset($data['primary_type'])
                && (string) $data['primary_type'] !== (string) ($originalData['primary_type'] ?? '')
            ) {
                $data['primary_type_source'] = 'user';
            }
        }
    }

    public function beforeDelete(array $data): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }

        $messageId = (int) $data['id'];

        // Cascade: extracted documents (Fáze 3a — spec §2.1)
        $this->db->query('DELETE FROM %n WHERE %n = %i', 'core_mail_extracted_documents', 'message', $messageId);

        // Cascade: analysis claims (Fáze 3a — spec §2.4)
        $this->db->query('DELETE FROM %n WHERE %n = %i', 'core_mail_analysis_claims', 'message', $messageId);

        // Cascade: smažeme analýzy (FK message → messages.id)
        $this->db->query('DELETE FROM %n WHERE %n = %i', 'core_mail_message_analyses', 'message', $messageId);

        // Cascade: smažeme obsahové attachmenty (table_id = 303, record_id = msg.id)
        $this->db->query(
            'DELETE FROM %n WHERE %n = %i AND %n = %i',
            'core_attachments_files',
            'table_id',
            self::MAIL_TABLE_ID,
            'record_id',
            $messageId,
        );
    }

    /**
     * Generuje `message_id` ve tvaru `MSG-YYYYMMDD-NNNN` pro daný den.
     * NNNN je sekvence v rámci dne (první zpráva dne = 0001).
     */
    private function generateMessageId(mixed $receivedAt): string
    {
        $ts = is_string($receivedAt) ? strtotime($receivedAt) : false;
        $day = $ts !== false ? date('Ymd', $ts) : date('Ymd');
        $prefix = self::ID_PREFIX . $day . '-';

        $row = $this->db->fetch(
            'SELECT MAX(CAST(SUBSTRING(%n, %i) AS UNSIGNED)) AS max_num
               FROM %n
              WHERE %n LIKE %s',
            'message_id',
            strlen($prefix) + 1,
            'core_mail_incoming_messages',
            'message_id',
            $prefix . '%',
        );

        $row = $row !== null ? (array) $row : [];
        $next = ((int) ($row['max_num'] ?? 0)) + 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Výchozí `analysis_state` nové zprávy: 10 (Ve frontě) když analýza není
     * explicitně vypnutá a v DS existuje aktivní AI profil, jinak 0.
     */
    private function resolveInitialAnalysisState(array $data): int
    {
        if (array_key_exists('ai_analysis_enabled', $data)
            && $data['ai_analysis_enabled'] !== null
            && !$data['ai_analysis_enabled']
        ) {
            return self::ANALYSIS_NONE;
        }
        if ($this->db === null) {
            return self::ANALYSIS_NONE;
        }

        $profile = $this->db->fetch(
            'SELECT %n FROM %n WHERE %n = %i LIMIT 1',
            'id',
            'core_mail_ai_profiles',
            'is_active',
            1,
        );

        return $profile !== null ? self::ANALYSIS_QUEUED : self::ANALYSIS_NONE;
    }

    /**
     * Zjistí výchozí primární typ pro zprávu. Nejprve zkusí `default_primary_type`
     * ze schránky, fallback je `other`.
     */
    private function resolveDefaultPrimaryType(?int $mailboxId): string
    {
        if ($this->db === null || $mailboxId === null || $mailboxId <= 0) {
            return 'other';
        }

        $row = $this->db->fetch(
            'SELECT %n FROM %n WHERE %n = %i',
            'default_primary_type',
            'core_mail_mailboxes',
            'id',
            $mailboxId,
        );
        $row = $row !== null ? (array) $row : [];
        $default = (string) ($row['default_primary_type'] ?? '');

        return $default !== '' ? $default : 'other';
    }
}
