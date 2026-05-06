<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class BankAccountDocument extends Document
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

        $accountNumber = trim((string) ($data['account_number'] ?? ''));
        $iban = strtoupper(trim((string) ($data['iban'] ?? '')));
        $bic = strtoupper(trim((string) ($data['bic'] ?? '')));

        if ($accountNumber === '' && $iban === '') {
            $result->addError(
                'account_number',
                'Musí být vyplněn alespoň jeden z údajů: Číslo účtu nebo IBAN.',
                'required_one_of',
            );
        }

        if ($iban !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            $result->addError('iban', 'IBAN má neplatný formát.', 'invalid_format');
        }

        if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            $result->addError('bic', 'BIC/SWIFT má neplatný formát.', 'invalid_format');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (isset($data['currency'])) {
            $data['currency'] = strtolower(trim((string) $data['currency']));
        }
        foreach (['code', 'name', 'notice', 'bank_name', 'account_number'] as $col) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
        foreach (['iban', 'bic'] as $col) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $data[$col] = strtoupper(trim((string) $data[$col]));
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
            'UPDATE [economy_codebooks_bank_accounts] SET [is_default] = 0
             WHERE [currency] = %s AND [is_default] = 1 AND [id] != %i',
            $currency,
            $id,
        );
    }
}
