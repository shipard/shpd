<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Detekuje chybějící vlastní bankovní spojení v číselníku
 * `economy_codebooks_bank_accounts`. Bez něj nejde potvrdit vydaná faktura
 * (`bank_account` je povinný, viz IssuedInvoiceDocument::validate).
 *
 * Aktivní účet = `docState IN (10, 40)`. `valid_to` v minulosti se
 * ZÁMĚRNĚ nefiltruje: BankAccountDocument platnost nevaliduje (jen
 * from <= to) a žádný runtime konzument ji nehonoruje — BankAccountsLookup
 * i BankStatementApplier filtrují jen docState. Check nesmí hlásit jako
 * chybějící účet, který systém reálně nabízí a používá.
 *
 * Spec: tasks/ds-setup-05-setup-checks.md, docs/ds-setup.md §5.3.
 */
final class MissingOwnBankAccountCheck extends AlertCheck
{
    /** docState 10 = Koncept, 40 = V pořádku — to jsou "aktivní" záznamy */
    private const ACTIVE_DOC_STATES = [10, 40];

    public function run(): array
    {
        $count = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_codebooks_bank_accounts'
                . ' WHERE docState IN %in',
            self::ACTIVE_DOC_STATES,
        );

        if ($count > 0) {
            return [];
        }

        $isCs = $this->language === 'cs';

        $title   = $isCs ? 'Chybí vlastní bankovní účet' : 'Own bank account is missing';
        $message = $isCs
            ? 'Založ v číselníku bankovní spojení firmy — tiskne se na vydané'
            . ' faktury a bez něj je nejde potvrdit.'
            : 'Add the company bank account to the codebook — it is printed on'
            . ' issued invoices, which cannot be confirmed without it.';

        $actionLabel = $isCs ? 'Založit bankovní účet' : 'Add bank account';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $title,
                message: $message,
                severity: 'warning',
                actions: [
                    [
                        'id'      => 'create_bank_account',
                        'label'   => $actionLabel,
                        'kind'    => 'open_form',
                        'target'  => [
                            'table' => 'economy_codebooks_bank_accounts',
                            'mode'  => 'create',
                        ],
                        'primary' => true,
                    ],
                ],
            ),
        ];
    }
}
