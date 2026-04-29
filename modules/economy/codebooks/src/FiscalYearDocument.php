<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class FiscalYearDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }
        if (empty($data['doc_number_prefix'])) {
            $result->addError('doc_number_prefix', 'Prefix čísla dokladu je povinný', 'required');
        }
        if (empty($data['date_begin'])) {
            $result->addError('date_begin', 'Začátek období je povinný', 'required');
        }
        if (empty($data['date_end'])) {
            $result->addError('date_end', 'Konec období je povinný', 'required');
        }
        if (empty($data['currency'])) {
            $result->addError('currency', 'Měna je povinná', 'required');
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

        if (!empty($data['currency']) && !preg_match('/^[a-z]{3}$/', (string) $data['currency'])) {
            $result->addError(
                'currency',
                'Měna musí být tříznakový kód malými písmeny (např. "czk").',
                'invalid_format',
            );
        }

        return $result;
    }
}
