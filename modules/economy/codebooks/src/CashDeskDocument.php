<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class CashDeskDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['code'])) {
            $result->addError('code', 'Kód je povinný', 'required');
        }
        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }
        if (empty($data['currency'])) {
            $result->addError('currency', 'Měna je povinná', 'required');
        } elseif (!preg_match('/^[a-z]{3}$/', (string) $data['currency'])) {
            $result->addError(
                'currency',
                'Měna musí být tříznakový kód malými písmeny (např. "czk").',
                'invalid_format',
            );
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $result->addError(
                'valid_to',
                'Platnost do nesmí být dříve než platnost od.',
                'invalid_range',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (isset($data['currency'])) {
            $data['currency'] = strtolower(trim((string) $data['currency']));
        }
        foreach (['code', 'name', 'notice'] as $col) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
    }

    public function afterPersist(array $data): void
    {
        if (empty($data['is_default'])) {
            return;
        }
        if (empty($data['id']) || empty($data['currency'])) {
            return;
        }

        $this->clearOtherDefaults((string) $data['currency'], (int) $data['id']);
    }

    protected function clearOtherDefaults(string $currency, int $id): void
    {
        if ($this->db === null) {
            return;
        }

        $this->db->query(
            'UPDATE [economy_codebooks_cash_desks] SET [is_default] = 0
             WHERE [currency] = %s AND [is_default] = 1 AND [id] != %i',
            $currency,
            $id,
        );
    }
}
