<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

/**
 * Zdroj karet domovského feedu. Bezstavový, napevno registrovaný v
 * `DashboardController` (D10 — module-driven registrace odložena).
 *
 * Karty vrací v kontraktu popsaném v `docs/dashboard.md` (kartový kontrakt):
 * `{id, source, kind, icon, stateStyle, title, subtitle, timestamp, context,
 * actions[]}`. Řazení a strop řeší controller (`sortAndCap`), zdroj karty jen
 * emituje.
 */
interface FeedSource
{
    /** @return list<array<string,mixed>> karty dle kontraktu */
    public function collectCards(FeedContext $ctx): array;
}
