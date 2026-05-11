<?php

declare(strict_types=1);

namespace Shipard\Module\Tasks\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class TaskDocument extends Document
{
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $result->addError('title', 'Název je povinný', 'required');
        } elseif (mb_strlen($title) > 200) {
            $result->addError('title', 'Název může mít maximálně 200 znaků', 'invalid');
        }

        $priority = $data['priority'] ?? null;
        if ($priority !== null && $priority !== '' && !in_array($priority, self::PRIORITIES, true)) {
            $result->addError('priority', 'Neznámá priorita', 'invalid');
        }

        if (!empty($data['due_date'])) {
            $date = $data['due_date'];
            $valid = false;
            if ($date instanceof \DateTimeInterface) {
                $valid = true;
            } elseif (is_string($date)) {
                $valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $date);
            }
            if (!$valid) {
                $result->addError('due_date', 'Neplatný termín', 'invalid');
            }
        }

        return $result;
    }
}
