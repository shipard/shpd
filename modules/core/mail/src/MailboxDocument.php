<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `core_mail_mailboxes`.
 *
 * Zodpovědnosti:
 *   - validace povinných polí (mailbox_id, name, email_address)
 *   - vynucení invariantu "max. jedna schránka s is_default = true per DS"
 *     (MariaDB nepodporuje filtrované unikátní indexy, proto aplikační validace)
 *   - audit pole (created, modified)
 */
class MailboxDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty(trim((string) ($data['mailbox_id'] ?? '')))) {
            $result->addError('mailbox_id', 'Kód schránky je povinný', 'required');
        }

        if (empty(trim((string) ($data['name'] ?? '')))) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $email = trim((string) ($data['email_address'] ?? ''));
        if ($email === '') {
            $result->addError('email_address', 'E-mailová adresa je povinná', 'required');
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $result->addError('email_address', 'E-mailová adresa není ve správném formátu', 'invalid_format');
        }

        if (!empty($data['is_default']) && $this->db !== null) {
            $existing = $this->findExistingDefault((int) ($data['id'] ?? 0));
            if ($existing !== null) {
                $result->addError(
                    'is_default',
                    "Výchozí schránka již existuje: {$existing}",
                    'duplicate_default',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        if (isset($data['email_address'])) {
            $data['email_address'] = strtolower(trim((string) $data['email_address']));
        }

        if (isset($data['is_default'])) {
            $data['is_default'] = $data['is_default'] ? 1 : 0;
        }

        if ($isNew) {
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
        }
        $data['modified'] = $now;
    }

    private function findExistingDefault(int $excludeId): ?string
    {
        $row = $this->db->fetch(
            'SELECT %n, %n FROM %n WHERE %n = %i AND %n != %i LIMIT 1',
            'mailbox_id',
            'name',
            'core_mail_mailboxes',
            'is_default',
            1,
            'id',
            $excludeId,
        );

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        return sprintf('%s (%s)', (string) ($row['name'] ?? ''), (string) ($row['mailbox_id'] ?? ''));
    }
}
