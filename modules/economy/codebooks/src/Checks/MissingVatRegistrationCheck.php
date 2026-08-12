<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Settings\SettingsStore;

/**
 * Detekuje plátce (`economy.vatAgenda === true`) bez aktivní Registrace DPH.
 * Bez registrace nejde potvrdit doklad s DPH (`vat_registration` je povinná,
 * viz DocDocument::validate).
 *
 * Mlčí u neplátce (`false`) i u nerozhodnutého klíče (`null`) — u
 * nerozhodnutého svítí `economy.codebooks.undecided_vat_agenda` a dvě
 * položky o tomtéž jsou šum. Striktně `=== true`, žádné `?? false`.
 *
 * Aktivní registrace = `docState IN (10, 40)`; na rozdíl od
 * VatAgendaNavGate (který počítá „nikdy žádná neexistovala" bez WHERE)
 * tady jde o použitelnou registraci pro nové doklady.
 *
 * Spec: tasks/ds-setup-05-setup-checks.md, docs/ds-setup.md §5.3.
 */
final class MissingVatRegistrationCheck extends AlertCheck
{
    /** docState 10 = Koncept, 40 = V pořádku — to jsou "aktivní" záznamy */
    private const ACTIVE_DOC_STATES = [10, 40];

    public function run(): array
    {
        if ((new SettingsStore($this->db))->get('economy.vatAgenda') !== true) {
            return [];
        }

        $count = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_codebooks_vat_registrations'
                . ' WHERE docState IN %in',
            self::ACTIVE_DOC_STATES,
        );

        if ($count > 0) {
            return [];
        }

        $isCs = $this->language === 'cs';

        $title   = $isCs ? 'Chybí Registrace DPH' : 'VAT registration is missing';
        $message = $isCs
            ? 'Firma vede agendu DPH, ale nemá založenou žádnou Registraci DPH.'
            . ' Bez ní nejde potvrdit doklad s DPH.'
            : 'The company keeps a VAT agenda but has no VAT registration.'
            . ' Documents with VAT cannot be confirmed without one.';

        $actionLabel = $isCs ? 'Založit Registraci DPH' : 'Add VAT registration';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $title,
                message: $message,
                severity: 'warning',
                actions: [
                    [
                        'id'      => 'create_vat_registration',
                        'label'   => $actionLabel,
                        'kind'    => 'open_form',
                        'target'  => [
                            'table' => 'economy_codebooks_vat_registrations',
                            'mode'  => 'create',
                        ],
                        'primary' => true,
                    ],
                ],
            ),
        ];
    }
}
