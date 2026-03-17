<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class PersonDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $personType = $data['person_type'] ?? null;

        if ($personType === null) {
            $result->addError('person_type', 'Typ osoby je povinný', 'required');
        }

        if ($personType === 'company' && empty($data['full_name'])) {
            $result->addError('full_name', 'Název firmy je povinný', 'required');
        }

        if ($personType === 'person') {
            if (empty($data['last_name'])) {
                $result->addError('last_name', 'Příjmení je povinné', 'required');
            }
            if (empty($data['first_name'])) {
                $result->addError('first_name', 'Jméno je povinné', 'required');
            }
        }

        return $result;
    }

    public function beforeSave(array &$data): void
    {
        $personType = $data['person_type'] ?? null;

        if ($personType === 'company') {
            $data['first_name'] = '';
            $data['last_name'] = $data['full_name'] ?? '';
        }

        if ($personType === 'person') {
            $data['full_name'] = trim(
                ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')
            );
        }
    }
}
