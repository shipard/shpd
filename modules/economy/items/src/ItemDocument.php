<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class ItemDocument extends Document
{
    private const CODE_GEN_ATTEMPTS = 10;
    private const CODE_GEN_BYTES = 3;       // 6 hex chars
    private const CODE_GEN_FALLBACK_BYTES = 4; // 8 hex chars

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $itemKind = $data['item_kind'] ?? null;
        if ($itemKind === null || $itemKind === '' || (int) $itemKind <= 0) {
            $result->addError('item_kind', 'Druh položky je povinný', 'required');
        } elseif ($this->db !== null) {
            $row = $this->db->fetch(
                'SELECT id FROM economy_items_kinds WHERE id = %i',
                (int) $itemKind,
            );
            if ($row === null || $row === false) {
                $result->addError('item_kind', 'Druh položky neexistuje', 'invalid');
            }
        }

        $unit = $data['unit'] ?? null;
        if ($unit === null || $unit === '' || (int) $unit <= 0) {
            $result->addError('unit', 'Jednotka je povinná', 'required');
        } elseif ($this->db !== null) {
            $row = $this->db->fetch(
                'SELECT id FROM core_units WHERE id = %i',
                (int) $unit,
            );
            if ($row === null || $row === false) {
                $result->addError('unit', 'Jednotka neexistuje', 'invalid');
            }
        }

        if (array_key_exists('sales_price_no_vat', $data)
            && $data['sales_price_no_vat'] !== null
            && $data['sales_price_no_vat'] !== ''
        ) {
            $price = (float) $data['sales_price_no_vat'];
            if ($price < 0) {
                $result->addError('sales_price_no_vat', 'Cena nesmí být záporná', 'invalid');
            }
        }

        // Účet (extension z economy.accounting) musí odkazovat na existující
        // aktivní analytický účet (account_level = 4). Klientský filtr
        // lookupu není bezpečnostní hranice — tady je tvrdé vynucení.
        if (!empty($data['accounting_account']) && $this->db !== null) {
            $row = $this->db->fetch(
                'SELECT id FROM economy_accounting_accounts'
                . ' WHERE id = %i AND account_level = 4 AND docState IN (10, 40, 80)',
                (int) $data['accounting_account'],
            );
            if ($row === null || $row === false) {
                $result->addError(
                    'accounting_account',
                    'Účet musí být existující aktivní analytický účet',
                    'invalid',
                );
            }
        }

        // Manuálně zadaný kód musí být unikátní
        if (!empty($data['code']) && $this->db !== null) {
            $existingId = !empty($data['id']) ? (int) $data['id'] : 0;
            $row = $existingId > 0
                ? $this->db->fetch(
                    'SELECT id FROM economy_items WHERE code = %s AND id <> %i',
                    (string) $data['code'],
                    $existingId,
                )
                : $this->db->fetch(
                    'SELECT id FROM economy_items WHERE code = %s',
                    (string) $data['code'],
                );
            if ($row !== null && $row !== false) {
                $result->addError('code', 'Kód položky již existuje', 'duplicate');
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        // Auto-generate code if missing
        if (empty($data['code']) && $this->db !== null) {
            $data['code'] = $this->generateCode();
        }

        // Denormalize item_type from item_kind. Always overrides whatever the
        // form sent — UI marks item_type readOnly, but we re-derive it server
        // side to keep the row consistent.
        if (!empty($data['item_kind']) && $this->db !== null) {
            $kind = $this->db->fetch(
                'SELECT item_type FROM economy_items_kinds WHERE id = %i',
                (int) $data['item_kind'],
            );
            if ($kind !== null && $kind !== false) {
                $data['item_type'] = (int) $kind['item_type'];
            }
        }
    }

    private function generateCode(): string
    {
        for ($i = 0; $i < self::CODE_GEN_ATTEMPTS; $i++) {
            $candidate = bin2hex(random_bytes(self::CODE_GEN_BYTES));
            if (!$this->codeExists($candidate)) {
                return $candidate;
            }
        }

        // Fallback: longer code, single attempt is overwhelmingly likely to be unique
        $fallback = bin2hex(random_bytes(self::CODE_GEN_FALLBACK_BYTES));
        if (!$this->codeExists($fallback)) {
            return $fallback;
        }

        // Extreme tail: append more entropy. Stays within the 25-char column.
        return $fallback . bin2hex(random_bytes(2));
    }

    private function codeExists(string $code): bool
    {
        $row = $this->db->fetch(
            'SELECT id FROM economy_items WHERE code = %s',
            $code,
        );
        return $row !== null && $row !== false;
    }
}
