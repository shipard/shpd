<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `base_registry_binders` (šanony Spisovny).
 *
 * Zodpovědnosti:
 *   - povinný název
 *   - unikátnost názvu mezi živými šanony (docState != 90) — aplikační
 *     validace, DB unique index nelze kvůli koši
 *   - audit pole (created)
 */
class BinderDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $result->addError('name', 'Název je povinný', 'required');
            return $result;
        }

        if ($this->db !== null) {
            $sql = 'SELECT id FROM base_registry_binders
                    WHERE name = %s AND docState != %i';
            $params = [$name, 90];
            if (!empty($data['id'])) {
                $sql .= ' AND id != %i';
                $params[] = (int) $data['id'];
            }
            $sql .= ' LIMIT 1';

            $existing = $this->db->fetch($sql, ...$params);
            if ($existing) {
                $result->addError(
                    'name',
                    "Šanon s názvem „{$name}“ už existuje",
                    'duplicate_name',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }

        if (empty($data['id']) && empty($data['created'])) {
            $data['created'] = date('Y-m-d H:i:s');
        }
    }
}
