<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class AccountDocument extends Document
{
    /**
     * Odvodí úroveň účtu a denormalizované prefixy z čísla účtu.
     * Jediný zdroj pravdy — používá `beforeSave()` i `AccountChartProvisioner`.
     *
     * @return array{account_level:int, g1:?string, g2:?string, g3:?string}
     */
    public static function deriveStructure(string $number): array
    {
        $n = trim($number);
        $len = strlen($n);
        $level = match (true) {
            $len === 1 => 1, // třída
            $len === 2 => 2, // skupina
            $len === 3 => 3, // syntetika
            default    => 4, // analytický účet (typicky 6 znaků)
        };
        return [
            'account_level' => $level,
            'g1' => $len >= 1 ? substr($n, 0, 1) : null,
            'g2' => $len >= 2 ? substr($n, 0, 2) : null,
            'g3' => $len >= 3 ? substr($n, 0, 3) : null,
        ];
    }

    public function validate(array &$data): ValidationResult
    {
        $r = new ValidationResult();

        // Třída/skupina/syntetika čistě číselné; analytika smí mít za
        // syntetikou i písmena — OSS konvence 343{CC}{NNN} (343DE120).
        $number = isset($data['number']) ? trim((string) $data['number']) : '';
        if ($number === '') {
            $r->addError('number', 'Číslo účtu je povinné', 'required');
        } elseif (!preg_match('/^[0-9]{1,3}$|^[0-9]{3}[0-9A-Z]{1,9}$/i', $number)) {
            $r->addError(
                'number',
                'Číslo účtu: 1–3 číslice (třída/skupina/syntetika), '
                . 'analytika 3 číslice + 1–9 číslic či písmen',
                'invalid',
            );
        }

        if (empty($data['name'])) {
            $r->addError('name', 'Název je povinný', 'required');
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $r->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
        }

        return $r;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['number', 'name', 'short_name'] as $col) {
            if (isset($data[$col])) {
                $data[$col] = trim((string) $data[$col]);
            }
        }

        if (isset($data['number']) && $data['number'] !== '') {
            $data['number'] = strtoupper((string) $data['number']);
            $structure = self::deriveStructure((string) $data['number']);
            $data['account_level'] = $structure['account_level'];
            $data['g1'] = $structure['g1'];
            $data['g2'] = $structure['g2'];
            $data['g3'] = $structure['g3'];
        }
    }
}
