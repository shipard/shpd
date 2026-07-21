<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Navigation\NavigationItemsProvider;

/**
 * Sidebar položky pro saldokonta se `show_in_navigation = 1` (checkbox
 * v Nastavení saldokont). Klik otevře Saldo pohyby (economy.accbal.ledger)
 * napevno filtrované přes `fixedViewGroup` = code — viewer pak chip lištu
 * saldokont nerenderuje.
 *
 * `_order` 31+ řadí položky hned za Saldo pohyby (navOrder 30); Výpisy mají
 * 40, takže bez kolize se vejde 9 saldokont — víc jich seed nemá a případná
 * remíza jen stabilně zařadí Výpisy před přetékající saldokonta.
 */
class BalancesNavigationProvider implements NavigationItemsProvider
{
    public function items(DataSourceConnection $db, string $language): array
    {
        // DS před ds-upgrade sloupec show_in_navigation nemá — dotaz by
        // shodil celou navigaci. Očekávatelný stav → prázdný seznam.
        try {
            $balances = $db->fetchAll(
                'SELECT `code`, `name`, `short_name` FROM `economy_accbal_balances`'
                . ' WHERE `show_in_navigation` = 1 AND `docState` != 90'
                . ' ORDER BY `sort_order` ASC, `name` ASC',
            );
        } catch (\Throwable) {
            return [];
        }

        $items = [];
        foreach ($balances as $i => $b) {
            $shortName = trim((string) ($b['short_name'] ?? ''));
            $items[] = [
                'id'             => 'accbal-balance:' . $b['code'],
                'label'          => $shortName !== '' ? $shortName : (string) $b['name'],
                'type'           => 'viewer',
                'viewerId'       => 'economy.accbal.ledger',
                'icon'           => 'calculator',
                'fixedViewGroup' => (string) $b['code'],
                '_section'       => 'accounting',
                '_order'         => 31 + $i,
            ];
        }
        return $items;
    }
}
