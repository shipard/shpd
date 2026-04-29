<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class VatPeriodDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['vat_registration'])) {
            $result->addError('vat_registration', 'Registrace DPH je povinná', 'required');
        }
        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }
        if (empty($data['date_begin'])) {
            $result->addError('date_begin', 'Začátek období je povinný', 'required');
        }
        if (empty($data['date_end'])) {
            $result->addError('date_end', 'Konec období je povinný', 'required');
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

        return $result;
    }
}
