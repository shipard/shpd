<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Settings\SettingsStore;

/**
 * Detekuje nerozhodnutý parametr `economy.fiscalYearStartMonth` (ds-setup.md
 * D2: absence klíče = nerozhodnuto). Bez rozhodnutí se neseedují fiskální
 * roky (D6) a doklady nejde zařadit do období.
 *
 * Bez akce — `open_panel` na panel checklistu dodá ds-setup Task 06/07.
 *
 * Spec: tasks/ds-setup-05-setup-checks.md, docs/ds-setup.md §5.3.
 */
final class UndecidedFiscalYearStartCheck extends AlertCheck
{
    public function run(): array
    {
        if ((new SettingsStore($this->db))->get('economy.fiscalYearStartMonth') !== null) {
            return [];
        }

        $isCs = $this->language === 'cs';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $isCs
                    ? 'Není zvolen první měsíc fiskálního roku'
                    : 'Fiscal year start month has not been chosen yet',
                message: $isCs
                    ? 'Zvol, kterým měsícem začíná fiskální rok (nejčastěji leden).'
                    . ' Do rozhodnutí se nezakládají fiskální roky a doklady nejde'
                    . ' zařadit do účetního období.'
                    : 'Choose which month the fiscal year starts with (most often'
                    . ' January). Until decided, fiscal years are not created and'
                    . ' documents cannot be assigned to an accounting period.',
                severity: 'warning',
            ),
        ];
    }
}
