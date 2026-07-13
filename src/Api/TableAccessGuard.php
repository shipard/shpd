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

	/** 403 pro ne-admina na core_system_* tabulce, jinak null. */
	public static function guardSystemTable(string $table, AuthContext $auth): ?Response
	{
		if (str_starts_with($table, self::SYSTEM_TABLE_PREFIX) && !$auth->isAdmin) {
			return Response::error(
				'FORBIDDEN_SYSTEM_TABLE',
				'System tables require administrator rights',
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
