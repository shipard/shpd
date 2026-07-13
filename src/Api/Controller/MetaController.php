<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;

class MetaController
{
	/**
	 * @param  array<string, TableDefinition>  $tableDefinitions  Indexed by table name
	 */
	public function tables(array $tableDefinitions, string $language): Response
	{
		$data = [];
		foreach ($tableDefinitions as $tableName => $def) {
			$data[] = [
				'table'   => $tableName,
				'name'    => $def->name,
				'tableId' => $def->tableId,
				'module'  => $this->deriveModule($tableName),
			];
		}

		usort($data, fn($a, $b) => $a['tableId'] <=> $b['tableId']);

		return Response::success($data);
	}

	/**
	 * @param  array<string, TableDefinition>  $tableDefinitions  Indexed by table name
	 */
	public function table(string $tableName, array $tableDefinitions, string $language): Response
	{
		$def = $tableDefinitions[$tableName] ?? null;
		if ($def === null) {
			return Response::error('TABLE_NOT_FOUND', "Table '{$tableName}' not found", 404);
		}

		$columns = [];
		foreach ($def->columns as $col) {
			// Sensitive sloupce nesmí do metadat — klient o nich nemá vědět
			// (negenerují se do gridů ani formulářů).
			if ($col->sensitive) {
				continue;
			}
			$columns[] = $this->serializeColumn($col);
		}

		$data = [
			'table'   => $tableName,
			'name'    => $def->name,
			'tableId' => $def->tableId,
			'module'  => $this->deriveModule($tableName),
		];

		if ($def->displayPattern !== null) {
			$data['displayPattern'] = $def->displayPattern;
		}

		$data['columns'] = $columns;

		if ($def->columnGroups !== []) {
			$data['columnGroups'] = $def->columnGroups;
		}

		if ($def->indexes !== []) {
			$indexes = [];
			foreach ($def->indexes as $idx) {
				$indexes[] = [
					'id'      => $idx->id,
					'type'    => $idx->type,
					'columns' => array_column($idx->columns, 'column'),
				];
			}
			$data['indexes'] = $indexes;
		}

		return Response::success($data);
	}

	private function serializeColumn(ColumnDefinition $col): array
	{
		$out = [
			'id'   => $col->id,
			'name' => $col->name,
			'type' => $col->type,
		];

		if ($col->primaryKey) {
			$out['primaryKey'] = true;
		}
		if ($col->autoIncrement) {
			$out['autoIncrement'] = true;
		}
		if ($col->length !== null) {
			$out['length'] = $col->length;
		}
		if ($col->precision !== null) {
			$out['precision'] = $col->precision;
		}
		if ($col->scale !== null) {
			$out['scale'] = $col->scale;
		}
		$out['nullable'] = $col->nullable;
		if ($col->default !== null) {
			$out['default'] = $col->default;
		}
		if ($col->group !== null) {
			$out['group'] = $col->group;
		}
		if ($col->cfgItem !== null) {
			$out['cfgItem'] = $col->cfgItem;
		}
		if ($col->reference !== null) {
			$out['reference'] = $col->reference;
		}

		return $out;
	}

	/** Derive module ID from table name: core_system_users → core.system */
	private function deriveModule(string $tableName): string
	{
		$parts = explode('_', $tableName, 3);
		if (count($parts) >= 2) {
			return $parts[0] . '.' . $parts[1];
		}
		return $tableName;
	}
}
