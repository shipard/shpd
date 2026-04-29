<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Units;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class UnitDocument extends Document
{
    /**
     * Povolené veličiny musí odpovídat klíčům v cfgItem `core.units.quantities`.
     * Držíme je zde lokálně, aby validate() nemusela načítat config — testy jsou
     * jednodušší a runtime se neběhá při každém ukládání proti compiled configu.
     */
    private const QUANTITIES = [
        'weight', 'volume', 'length', 'area', 'time', 'energy', 'count', 'other',
    ];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        if (empty($data['shortcut'])) {
            $result->addError('shortcut', 'Zkratka je povinná', 'required');
        }

        $quantity = $data['quantity'] ?? null;
        if ($quantity === null || $quantity === '') {
            $result->addError('quantity', 'Veličina je povinná', 'required');
        } elseif (!in_array($quantity, self::QUANTITIES, true)) {
            $result->addError('quantity', 'Neznámá veličina', 'invalid');
        }

        if (array_key_exists('coefficient', $data) && $data['coefficient'] !== null && $data['coefficient'] !== '') {
            $coef = (float) $data['coefficient'];
            if ($coef <= 0) {
                $result->addError('coefficient', 'Koeficient musí být kladný', 'invalid');
            }
        }

        return $result;
    }
}
