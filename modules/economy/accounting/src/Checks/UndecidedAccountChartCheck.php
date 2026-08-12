<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Settings\SettingsStore;

/**
 * Detekuje nerozhodnutý parametr `economy.accountChart` (ds-setup.md D2:
 * absence klíče = nerozhodnuto). Bez rozhodnutí se neseeduje účtová osnova
 * a doklady nejde zaúčtovat.
 *
 * Rozhodnutí `none` (vlastní osnova, neseedovat) je platná hodnota —
 * check pak mlčí, fires jen na chybějící klíč.
 *
 * Bez akce — `open_panel` na panel checklistu dodá ds-setup Task 06/07.
 *
 * Spec: tasks/ds-setup-05-setup-checks.md, docs/ds-setup.md §5.3.
 */
final class UndecidedAccountChartCheck extends AlertCheck
{
    public function run(): array
    {
        if ((new SettingsStore($this->db))->get('economy.accountChart') !== null) {
            return [];
        }

        $isCs = $this->language === 'cs';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $isCs
                    ? 'Není zvolena účtová osnova'
                    : 'Account chart has not been chosen yet',
                message: $isCs
                    ? 'Vyber variantu účtové osnovy (podnikatelé / nezisková'
                    . ' organizace / vlastní). Do rozhodnutí se osnova neseeduje'
                    . ' a doklady nejde zaúčtovat.'
                    : 'Choose the account chart variant (business / non-profit /'
                    . ' custom). Until decided, the chart is not seeded and'
                    . ' documents cannot be posted.',
                severity: 'warning',
            ),
        ];
    }
}
