<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Database\TableDefinition;

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
	 */
	public static function rejectSensitiveInput(array $body, TableDefinition $def): ?Response
	{
		foreach ($def->getSensitiveColumns() as $col) {
			if (array_key_exists($col, $body)) {
				return Response::error(
					'SENSITIVE_COLUMN',
					"Column '{$col}' cannot be written through the generic API",
					400,
				);
			}
		}
		return null;
	}
}
