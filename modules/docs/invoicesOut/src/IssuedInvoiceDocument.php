<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Issued invoice (FVB) — `doc_type = 'invno'`.
 *
 * Inherits all logic from DocsHeadsDocument; overrides validate() with
 * per-type rules:
 *   - bank_account is required at Confirm time (we need to tell the
 *     customer where to pay)
 */
class IssuedInvoiceDocument extends DocsHeadsDocument
{
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        $newState = (int) ($data['docState'] ?? 10);

        // Confirm and beyond: our bank account must be set on issued invoices
        if (in_array($newState, [40, 80], true)) {
            if (empty($data['bank_account'])) {
                $result->addError(
                    'bank_account',
                    'Bankovní účet je povinný — partner musí vědět, kam zaplatit.',
                    'required',
                );
            }
        }

        return $result;
    }
}
