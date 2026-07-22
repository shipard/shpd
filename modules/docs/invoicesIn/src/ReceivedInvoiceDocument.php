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
 * recommend some form of supplier bank info at Confirm time (warning, save
 * proceeds). The hard requirement belongs to the future payment flow (payment
 * order creation from the document) — when that module exists, take it over
 * from here.
 */
class ReceivedInvoiceDocument extends DocsHeadsDocument
{
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        $newState = (int) ($data['docState'] ?? 10);
        $paymentMethod = (int) ($data['payment_method'] ?? 1);

        // Confirm and beyond: at least one of partner_bank, partner_bank_account,
        // or partner_bank_iban should be filled — we need to know how to pay.
        // Only relevant for bank transfer (payment_method === 1). Cash / card /
        // cash-on-delivery / set-off don't need supplier bank info. Historical
        // (paid) invoices legitimately lack it, so this must not block saves.
        if (in_array($newState, [20, 40, 80], true) && $paymentMethod === 1) {
            $hasBank = !empty($data['partner_bank'])
                || !empty($data['partner_bank_account'])
                || !empty($data['partner_bank_iban']);
            if (!$hasBank) {
                // Bind to the `partner_bank` column (lookup on the header tab) —
                // the check covers partner_bank / partner_bank_account /
                // partner_bank_iban, but the lookup is the primary
                // "vyberte jeho účet" entry point.
                $result->addWarning(
                    'partner_bank',
                    'Bankovní spojení dodavatele je povinné — vyberte jeho účet '
                    . 'nebo vyplňte ručně číslo účtu / IBAN.',
                    'partner_bank_recommended',
                );
            }
        }

        return $result;
    }
}
