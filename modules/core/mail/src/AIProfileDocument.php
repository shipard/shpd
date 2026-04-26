<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `core_mail_ai_profiles`.
 *
 * Zodpovědnosti:
 *   - validace povinných polí (profile_id, name, backend, prompt_template,
 *     output_schema, supported_doc_types, confidence_thresholds)
 *   - validace JSON polí (output_schema, supported_doc_types,
 *     confidence_thresholds) — neukládáme rozbité JSON
 *   - vynucení invariantu "max. jeden profil s is_default = true per DS"
 *   - audit pole (created, modified)
 */
class AIProfileDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty(trim((string) ($data['profile_id'] ?? '')))) {
            $result->addError('profile_id', 'Kód profilu je povinný', 'required');
        }

        if (empty(trim((string) ($data['name'] ?? '')))) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $backend = isset($data['backend']) ? (int) $data['backend'] : 0;
        if ($backend <= 0) {
            $result->addError('backend', 'Backend je povinný', 'required');
        }

        if (empty(trim((string) ($data['prompt_template'] ?? '')))) {
            $result->addError('prompt_template', 'Šablona promptu je povinná', 'required');
        }

        $this->validateJsonField($data, 'output_schema', $result, 'object');
        $this->validateJsonField($data, 'supported_doc_types', $result, 'array');
        $this->validateJsonField($data, 'confidence_thresholds', $result, 'object');

        if (!empty($data['is_default']) && $this->db !== null) {
            $existing = $this->findExistingDefault((int) ($data['id'] ?? 0));
            if ($existing !== null) {
                $result->addError(
                    'is_default',
                    "Výchozí profil již existuje: {$existing}",
                    'duplicate_default',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        if (isset($data['is_default'])) {
            $data['is_default'] = $data['is_default'] ? 1 : 0;
        }
        if (isset($data['is_active'])) {
            $data['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if ($isNew) {
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
        }
        $data['modified'] = $now;
    }

    /**
     * @param 'object'|'array' $expected
     */
    private function validateJsonField(
        array $data,
        string $field,
        ValidationResult $result,
        string $expected,
    ): void {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            $result->addError($field, "Pole {$field} je povinné", 'required');
            return;
        }

        $raw = (string) $data[$field];
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result->addError(
                $field,
                'Pole musí obsahovat platné JSON: ' . json_last_error_msg(),
                'invalid_json',
            );
            return;
        }

        if ($expected === 'array') {
            if (!is_array($decoded) || (count($decoded) > 0 && !array_is_list($decoded))) {
                $result->addError($field, 'JSON musí být pole', 'invalid_json_shape');
            }
            return;
        }

        if ($expected === 'object') {
            if (!is_array($decoded) || (count($decoded) > 0 && array_is_list($decoded))) {
                $result->addError($field, 'JSON musí být objekt', 'invalid_json_shape');
            }
        }
    }

    private function findExistingDefault(int $excludeId): ?string
    {
        $row = $this->db->fetch(
            'SELECT %n, %n FROM %n WHERE %n = %i AND %n != %i LIMIT 1',
            'profile_id',
            'name',
            'core_mail_ai_profiles',
            'is_default',
            1,
            'id',
            $excludeId,
        );

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        return sprintf('%s (%s)', (string) ($row['name'] ?? ''), (string) ($row['profile_id'] ?? ''));
    }
}
