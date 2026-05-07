<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Received invoice (FPB) — `doc_type = 'invni'`.
 *
 * Inherits all logic from DocsHeadsDocument. For received invoices we don't
 * require our `bank_account` (the supplier provides theirs) — instead we
 * require some form of supplier bank info at Confirm time.
 */
class ReceivedInvoiceDocument extends DocsHeadsDocument
{
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        $newState = (int) ($data['docState'] ?? 10);

        // Confirm and beyond: at least one of partner_bank, partner_bank_account,
        // or partner_bank_iban must be filled — we need to know how to pay.
        if (in_array($newState, [20, 40, 80], true)) {
            $hasBank = !empty($data['partner_bank'])
                || !empty($data['partner_bank_account'])
                || !empty($data['partner_bank_iban']);
            if (!$hasBank) {
                $result->addError(
                    '_form',
                    'Bankovní spojení dodavatele je povinné — vyberte jeho účet '
                    . 'nebo vyplňte ručně číslo účtu / IBAN.',
                    'partner_bank_required',
                );
            }
        }

        return $result;
    }
}
