<?php
declare(strict_types=1);

namespace Shipard\Api;

/**
 * Verdikt ReadOnlyPolicy pro routu na DS ve stavu `read_only` (#56 fáze 2).
 */
enum ReadOnlyVerdict
{
	/** Routa nezapisuje — pustit. */
	case Allow;

	/** Uživatelská mutace — 403 `DS_READ_ONLY`, klient má zobrazit, ne retryovat. */
	case Deny403;

	/**
	 * Strojový ingest (mail-router, AI analyzer) — 503 `DS_UNAVAILABLE` +
	 * Retry-After jako u zavřeného DS (D4): volající frontuje a práci
	 * nezahazuje, až se DS otevře, doručí.
	 */
	case Deny503;
}
