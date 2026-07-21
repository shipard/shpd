<?php

declare(strict_types=1);

namespace Shipard\Core\Navigation;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Dynamické položky hlavní navigace (sidebar, app mód) odvozené z dat.
 *
 * Modul registruje implementaci v module.jsonc klíčem `navigationProviders`
 * ({class}); NavigationController providery resolvnutých modulů instancuje
 * a jejich položky přimerguje ke statickým z collectItems(). Providery běží
 * jen v app navigaci (ne settings/account) a jen když je k dispozici DB.
 *
 * První konzument: BalancesNavigationProvider (saldokonta se
 * show_in_navigation = 1 jako položky vedle Saldo pohybů).
 */
interface NavigationItemsProvider
{
    /**
     * Vrací položky ve tvaru NavigationController::collectItems():
     * `id`, `label`, `type: 'viewer'`, `viewerId`, `icon`, volitelně
     * `fixedViewGroup`, interní `_section` a `_order` (bucketování +
     * řazení; z API výstupu je maže cleanItem()).
     *
     * Dotaz drž triviální — provider běží při každém načtení navigace.
     * Výjimka navigaci neshodí (controller loguje a pokračuje), ale stavy
     * očekávatelné za běhu (např. sloupec před ds-upgrade) ošetři uvnitř
     * a vrať prázdný seznam.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(DataSourceConnection $db, string $language): array;
}
