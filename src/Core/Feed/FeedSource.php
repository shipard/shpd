<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

/**
 * Zdroj karet domovského feedu. Bezstavový, napevno registrovaný v
 * `DashboardController` (D10 — module-driven registrace odložena).
 *
 * Karty vrací v kontraktu popsaném v `docs/dashboard.md` (kartový kontrakt):
 * `{id, source, kind, icon, stateStyle, category?, title, subtitle, timestamp,
 * context, actions[]}`. Řazení a strop řeší controller (`sortAndCap`), zdroj
 * karty jen emituje. `category` (CATEGORY_*) řídí klientský filtr feedu —
 * karta bez pole se zobrazuje jen v záložce Vše.
 */
interface FeedSource
{
    /** Kategorie karet pro filtr feedu (docs/dashboard.md §4). */
    public const string CATEGORY_INVOICES = 'invoices';
    public const string CATEGORY_REGISTRY = 'registry';
    public const string CATEGORY_OTHER    = 'other';

    /** @return list<array<string,mixed>> karty dle kontraktu */
    public function collectCards(FeedContext $ctx): array;
}
