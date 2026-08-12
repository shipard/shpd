<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Settings\SettingsStore;

/**
 * Detekuje nerozhodnutý parametr `economy.vatAgenda` (ds-setup.md D2:
 * absence klíče = nerozhodnuto). Dokud není rozhodnuto, mají nové doklady
 * výchozí režim s DPH a agenda DPH je vidět — uživatel by měl říct, jestli
 * vede agendu DPH.
 *
 * Bez akce — `open_panel` na panel checklistu dodá ds-setup Task 06/07.
 *
 * Spec: tasks/ds-setup-05-setup-checks.md, docs/ds-setup.md §5.3.
 */
final class UndecidedVatAgendaCheck extends AlertCheck
{
    public function run(): array
    {
        // Striktně === null: false je platné rozhodnutí (neplátce), žádné ?? false.
        if ((new SettingsStore($this->db))->get('economy.vatAgenda') !== null) {
            return [];
        }

        $isCs = $this->language === 'cs';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $isCs
                    ? 'Není rozhodnuto, zda se vede agenda DPH'
                    : 'VAT agenda has not been decided yet',
                message: $isCs
                    ? 'Urči, jestli firma vede agendu DPH (plátce / identifikovaná'
                    . ' osoba). Řídí výchozí režim DPH nových dokladů'
                    . ' a viditelnost agendy DPH v aplikaci.'
                    : 'Decide whether the company keeps a VAT agenda (VAT payer /'
                    . ' identified person). It drives the default VAT mode of new'
                    . ' documents and the visibility of the VAT agenda in the app.',
                severity: 'warning',
            ),
        ];
    }
}
