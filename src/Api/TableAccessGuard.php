<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Database\TableDefinition;
use Shipard\Core\StructuredFields\StructuredSchema;

/**
 * Plošná ochrana systémových tabulek a citlivých sloupců, sdílená všemi
 * cestami, které čtou/zapisují tabulková data (CRUD, viewer, form).
 */
final class TableAccessGuard
{
	public const SYSTEM_TABLE_PREFIX = 'core_system_';

	/**
	 * 403 pro ne-admina, jinak null. Dvě větve:
	 *  - core_system_* tabulky (prefix match) → FORBIDDEN_SYSTEM_TABLE,
	 *  - tabulky s "adminOnly": true v definici → FORBIDDEN_ADMIN_ONLY
	 *    (viz docs/hosting.md, rozhodnutí D9).
	 * Bez TableDefinition ($def === null) se vynucuje jen prefix.
	 */
	public static function guardTable(string $table, AuthContext $auth, ?TableDefinition $def = null): ?Response
	{
		if ($auth->isAdmin) {
			return null;
		}
		if (str_starts_with($table, self::SYSTEM_TABLE_PREFIX)) {
			return Response::error(
				'FORBIDDEN_SYSTEM_TABLE',
				'System tables require administrator rights',
				403,
			);
		}
		if ($def?->adminOnly === true) {
			return Response::error(
				'FORBIDDEN_ADMIN_ONLY',
				'Table requires administrator rights',
				403,
			);
		}
		return null;
	}

	/** Odstraní sensitive sloupce z řádku před odesláním klientovi. */
	public static function stripSensitive(array $row, TableDefinition $def): array
	{
		foreach ($def->getSensitiveColumns() as $col) {
			unset($row[$col]);
		}
		return $row;
	}

	/**
	 * 400 pokud vstup obsahuje sensitive sloupec — zápis jde vždy jen
	 * dedikovaným endpointem, žádné tiché zahazování.
	 *
	 * `$allowed` = opt-in whitelist z formu (TableForm::
	 * getEditableSensitiveColumns) — jen form save s registrovanou form
	 * třídou může sensitive sloupec explicitně povolit; CRUD cesty
	 * volají bez whitelistu.
	 *
	 * @param list<string> $allowed
	 */
	public static function rejectSensitiveInput(array $body, TableDefinition $def, array $allowed = []): ?Response
	{
		foreach ($def->getSensitiveColumns() as $col) {
			if (array_key_exists($col, $body) && !in_array($col, $allowed, true)) {
				return Response::error(
					'SENSITIVE_COLUMN',
					"Column '{$col}' cannot be written through the generic API",
					400,
				);
			}
		}
		return null;
	}

	/**
	 * 400 pokud vstup zapisuje strukturované pole (#74) — ani celý sloupec,
	 * ani virtuální `<sloupec>.<pole>`.
	 *
	 * Hodnotu strukturovaného pole validuje a `_schema` do ní stampuje jedině
	 * `TableGateway` (rozhodnutí I3); generické CRUD dokumentovou vrstvu
	 * záměrně obchází a zapisuje přímo do tabulky, takže by uložilo
	 * nevalidovaný obsah bez verze schématu — a ten by pak šel do XML podání.
	 * Zápis patří form endpointu (`POST /_ui/form/{table}/save`) nebo kódu,
	 * který jde přes gateway (applier, seeder, CLI).
	 *
	 * Fail-closed: radši odmítnutý zápis než tiše zahozený virtuální sloupec.
	 */
	public static function rejectStructuredInput(array $body, TableDefinition $def): ?Response
	{
		foreach (array_keys($def->getStructuredColumns()) as $col) {
			$prefix = $col . StructuredSchema::PATH_SEPARATOR;
			foreach (array_keys($body) as $key) {
				$key = (string) $key;
				if ($key === $col || str_starts_with($key, $prefix)) {
					return Response::error(
						'STRUCTURED_COLUMN',
						"Column '{$col}' is a structured field and cannot be written through the generic API"
						. ' — use the form endpoint (see docs/structured-fields.md)',
						400,
					);
				}
			}
		}
		return null;
	}
}
