<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class ItemKindDocument extends Document
{
    /** Hodnoty musí korespondovat s `economy.items.itemTypes` cfgItem. */
    private const ITEM_TYPES = [0, 1, 2, 3];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $itemType = $data['item_type'] ?? null;
        if ($itemType === null || $itemType === '') {
            $result->addError('item_type', 'Typ položky je povinný', 'required');
        } else {
            $itemTypeInt = (int) $itemType;
            if (!in_array($itemTypeInt, self::ITEM_TYPES, true)) {
                $result->addError('item_type', 'Neznámý typ položky', 'invalid');
            } elseif (!empty($data['id']) && $this->db !== null) {
                // Existující záznam: zákaz změny item_type, pokud je druh už použit
                $existing = $this->db->fetch(
                    'SELECT item_type FROM economy_items_kinds WHERE id = %i',
                    (int) $data['id'],
                );
                $previous = $existing !== null && $existing !== false ? (int) $existing['item_type'] : null;

                if ($previous !== null && $previous !== $itemTypeInt) {
                    $usage = $this->db->fetch(
                        'SELECT COUNT(*) AS n FROM economy_items WHERE item_kind = %i',
                        (int) $data['id'],
                    );
                    $usageCount = $usage !== null && $usage !== false ? (int) $usage['n'] : 0;
                    if ($usageCount > 0) {
                        $result->addError(
                            'item_type',
                            "Typ položky nelze změnit — druh je již použit u {$usageCount} položek.",
                            'in_use',
                        );
                    }
                }
            }
        }

        return $result;
    }
}
