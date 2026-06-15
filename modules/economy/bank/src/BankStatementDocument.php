<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document class bankovního výpisu (economy_bank_statements).
 *
 * Výpis je nepovinná evidenční/kontrolní vrstva nad transakcemi. Vzniká
 * importem (Fáze 2) nebo migrací (Fáze 4). PDF výpisu se připojí přes
 * core.attachments (tab Přílohy ve formu). Vlastní rekonciliace
 * (opening_balance + Σ příjmů − Σ výdajů == closing_balance) je Fáze 2.
 */
class BankStatementDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $r = new ValidationResult();

        if (empty($data['bank_account'])) {
            $r->addError('bank_account', 'Bankovní účet je povinný.', 'required');
        }

        if (empty($data['currency'])) {
            $r->addError('currency', 'Měna je povinná.', 'required');
        }

        if (!empty($data['period_start']) && !empty($data['period_end'])
            && (string) $data['period_start'] > (string) $data['period_end']
        ) {
            $r->addError('period_end', 'Konec období nesmí být dříve než jeho začátek.', 'invalid_range');
        }

        return $r;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (isset($data['currency'])) {
            $data['currency'] = strtolower(trim((string) $data['currency']));
        }
        if (isset($data['statement_number']) && $data['statement_number'] !== null) {
            $data['statement_number'] = trim((string) $data['statement_number']);
        }
    }
}
