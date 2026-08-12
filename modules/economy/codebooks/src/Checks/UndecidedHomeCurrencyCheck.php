<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Settings\SettingsStore;

/**
 * Detekuje nerozhodnutý parametr `economy.homeCurrency` (ds-setup.md D2:
 * absence klíče = nerozhodnuto). Bez rozhodnutí se neseedují fiskální roky
 * (D6, měna je součástí zakládaného záznamu) a nové doklady padají na
 * defenzivní default.
 *
 * Bez akce — `open_panel` na panel checklistu dodá ds-setup Task 06/07.
 *
 * Spec: tasks/ds-setup-05-setup-checks.md, docs/ds-setup.md §5.3.
 */
final class UndecidedHomeCurrencyCheck extends AlertCheck
{
    public function run(): array
    {
        if ((new SettingsStore($this->db))->get('economy.homeCurrency') !== null) {
            return [];
        }

        $isCs = $this->language === 'cs';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $isCs
                    ? 'Není zvolena domácí měna'
                    : 'Home currency has not been chosen yet',
                message: $isCs
                    ? 'Zvol domácí měnu účetnictví (např. CZK). Nové doklady ji'
                    . ' dostávají jako výchozí a zakládají se s ní fiskální roky.'
                    : 'Choose the home currency of the books (e.g. CZK). New'
                    . ' documents use it as the default and fiscal years are'
                    . ' created with it.',
                severity: 'warning',
            ),
        ];
    }
}
