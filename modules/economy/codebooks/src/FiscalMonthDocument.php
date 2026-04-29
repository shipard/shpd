<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class FiscalMonthDocument extends Document
{
    private const VALID_PERIOD_TYPES = [0, 1, 2];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['fiscal_year'])) {
            $result->addError('fiscal_year', 'Fiskální rok je povinný', 'required');
        }
        if (empty($data['date_begin'])) {
            $result->addError('date_begin', 'Začátek období je povinný', 'required');
        }
        if (empty($data['date_end'])) {
            $result->addError('date_end', 'Konec období je povinný', 'required');
        }
        if (!isset($data['period_type']) || $data['period_type'] === '' || $data['period_type'] === null) {
            $result->addError('period_type', 'Typ období je povinný', 'required');
        }

        if (!empty($data['date_begin']) && !empty($data['date_end'])
            && (string) $data['date_begin'] > (string) $data['date_end']
        ) {
            $result->addError(
                'date_end',
                'Konec období musí být později nebo stejný den jako začátek.',
                'invalid_range',
            );
        }

        if (isset($data['period_type']) && $data['period_type'] !== '' && $data['period_type'] !== null
            && !in_array((int) $data['period_type'], self::VALID_PERIOD_TYPES, true)
        ) {
            $result->addError(
                'period_type',
                'Neplatný typ období.',
                'invalid_value',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data): void
    {
        if (empty($data['date_begin'])) {
            return;
        }

        $begin = new \DateTimeImmutable((string) $data['date_begin']);
        $data['calendar_year'] = (int) $begin->format('Y');
        $data['calendar_month'] = (int) $begin->format('n');
    }
}
