<?php

declare(strict_types=1);

namespace Shipard\Core\Navigation;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Runtime podmínka viditelnosti navigační položky odvozená z dat
 * (protějšek NavigationItemsProvider, který položky přidává).
 *
 * Modul registruje implementaci klíčem `visibilityClass` na položce
 * `settingsItems[]` / `accountItems[]` v module.jsonc; SettingsController
 * gate instancuje (jedna instance per třída per request) a položku
 * s `isVisible() === false` ze stromu vynechá.
 *
 * Fail-open: bez DB spojení, při chybějící třídě i při výjimce z gate se
 * položka ZOBRAZÍ (controller loguje) — neúplné nastavení nebo porucha
 * nesmí schovávat funkčnost.
 *
 * První konzument: VatAgendaNavGate (agenda DPH u neplátce bez jediné
 * registrace, ds-setup.md D11).
 */
interface NavItemVisibilityGate
{
    /**
     * Dotaz drž triviální — gate běží při každém načtení settings
     * navigace. Stavy očekávatelné za běhu (např. tabulka před
     * ds-upgrade) ošetři uvnitř a vrať true.
     */
    public function isVisible(DataSourceConnection $db): bool;
}
