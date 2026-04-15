<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document class for core_attachments_files.
 *
 * Validates required fields for attachment records.
 */
class AttachmentDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['table_id'])) {
            $result->addError('table_id', 'ID tabulky je povinné', 'required');
        }

        if (empty($data['record_id'])) {
            $result->addError('record_id', 'ID záznamu je povinné', 'required');
        }

        if (empty($data['name'])) {
            $result->addError('name', 'Název přílohy je povinný', 'required');
        }

        return $result;
    }
}
