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

        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        if ($personType === null || $personType === PersonType::Undefined) {
            $result->addError('person_type', 'Typ osoby je povinný', 'required');
        }

        if ($personType === PersonType::Company && empty($data['full_name'])) {
            $result->addError('full_name', 'Název firmy je povinný', 'required');
        }

        if ($personType === PersonType::Person) {
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
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        // Auto-generate person_id if empty
        if (empty($data['person_id']) && $this->db !== null) {
            $data['person_id'] = $this->generatePersonId($personType);
        }

        if ($personType === PersonType::Company) {
            $data['first_name'] = '';
            $data['last_name'] = $data['full_name'] ?? '';
        }

        if ($personType === PersonType::Person) {
            $data['full_name'] = trim(
                ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')
            );
        }
    }

    private function generatePersonId(?PersonType $personType): string
    {
        $prefix = match ($personType) {
            PersonType::Company => 'F',
            PersonType::Person  => 'O',
            default             => 'X',
        };

        // Find the highest existing numeric suffix for this prefix
        $row = $this->db->fetch(
            'SELECT MAX(CAST(SUBSTRING(person_id, %i) AS UNSIGNED)) AS max_num
             FROM base_persons_persons
             WHERE person_id LIKE %s',
            strlen($prefix) + 1,
            $prefix . '%',
        );

        $next = ((int) ($row['max_num'] ?? 0)) + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
