<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class CostCenterDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $r = new ValidationResult();
        if (empty($data['code'])) {
            $r->addError('code', 'Kód je povinný', 'required');
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

    public function beforeSave(array &$data): void
    {
        foreach (['code', 'name'] as $col) {
            if (isset($data[$col])) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
    }
}
