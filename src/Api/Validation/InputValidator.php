<?php
declare(strict_types=1);

namespace Shipard\Api\Validation;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;

class InputValidator
{
	/** Auto-managed fields that clients must never send. */
	private const AUTO_FIELDS = ['id', 'created', 'modified'];

	/**
	 * Validate input data against a table definition.
	 *
	 * @param  array           $data   Input fields from request body
	 * @param  TableDefinition $table  Table definition
	 * @param  string          $mode   'create' (POST/PUT) or 'patch' (PATCH)
	 * @return array<array{field:string,code:string,message:string}>  Empty = valid
	 */
	public function validate(array $data, TableDefinition $table, string $mode, ?ConfigRuntime $config = null): array
	{
		$errors = [];

		/** @var array<string, ColumnDefinition> */
		$columnMap = [];
		foreach ($table->columns as $col) {
			$columnMap[$col->id] = $col;
		}

		// In create mode: check that all required (non-nullable, no default) fields are present.
		if ($mode === 'create') {
			foreach ($table->columns as $col) {
				if (in_array($col->id, self::AUTO_FIELDS, true)) {
					continue;
				}
				if ($col->primaryKey || $col->autoIncrement) {
					continue;
				}
				if (!$col->nullable && $col->default === null && !array_key_exists($col->id, $data)) {
					$errors[] = [
						'field'   => $col->id,
						'code'    => 'REQUIRED',
						'message' => "Field '{$col->id}' is required",
					];
				}
			}
		}

		// Validate present field values (both modes).
		foreach ($data as $fieldId => $value) {
			$fieldId = (string) $fieldId;

			if (in_array($fieldId, self::AUTO_FIELDS, true)) {
				continue; // silently skip auto fields
			}

			if (!isset($columnMap[$fieldId])) {
				continue; // unknown columns are silently ignored
			}

			$col = $columnMap[$fieldId];

			if ($value === null) {
				if (!$col->nullable) {
					$errors[] = [
						'field'   => $fieldId,
						'code'    => 'NULL_NOT_ALLOWED',
						'message' => "Field '{$fieldId}' cannot be null",
					];
				}
				continue;
			}

			$fieldErrors = $this->validateField($col, $value, $config);
			foreach ($fieldErrors as $e) {
				$errors[] = $e;
			}
		}

		return $errors;
	}

	/** @return array<array{field:string,code:string,message:string}> */
	private function validateField(ColumnDefinition $col, mixed $value, ?ConfigRuntime $config): array
	{
		return match ($col->type) {
			'varchar'    => $this->validateVarchar($col, $value),
			'text', 'longtext' => $this->validateString($col, $value),
			'int'        => $this->validateInt($col, $value, -2147483648, 2147483647),
			'smallint'   => $this->validateInt($col, $value, -32768, 32767),
			'bigint'     => $this->validateInt($col, $value, PHP_INT_MIN, PHP_INT_MAX),
			'tinyint'    => $this->validateInt($col, $value, -128, 127),
			'numeric', 'float' => $this->validateNumeric($col, $value),
			'boolean'    => $this->validateBoolean($col, $value),
			'date'       => $this->validateDate($col, $value),
			'datetime'   => $this->validateDatetime($col, $value),
			'time'       => $this->validateTime($col, $value),
			'json'       => $this->validateJson($col, $value),
			'enumInt', 'enumString' => $this->validateEnum($col, $value, $config),
			default      => [],
		};
	}

	private function validateVarchar(ColumnDefinition $col, mixed $value): array
	{
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			return [['field' => $col->id, 'code' => 'INVALID_TYPE', 'message' => "Field '{$col->id}' must be a string"]];
		}
		$str = (string) $value;
		if ($col->length !== null && mb_strlen($str) > $col->length) {
			return [['field' => $col->id, 'code' => 'TOO_LONG', 'message' => "Field '{$col->id}' exceeds maximum length of {$col->length}"]];
		}
		return [];
	}

	private function validateString(ColumnDefinition $col, mixed $value): array
	{
		if (!is_string($value)) {
			return [['field' => $col->id, 'code' => 'INVALID_TYPE', 'message' => "Field '{$col->id}' must be a string"]];
		}
		return [];
	}

	private function validateInt(ColumnDefinition $col, mixed $value, int $min, int $max): array
	{
		if (!is_int($value) && !(is_string($value) && ctype_digit(ltrim($value, '-')))) {
			return [['field' => $col->id, 'code' => 'INVALID_TYPE', 'message' => "Field '{$col->id}' must be an integer"]];
		}
		$int = (int) $value;
		if ($int < $min || $int > $max) {
			return [['field' => $col->id, 'code' => 'OUT_OF_RANGE', 'message' => "Field '{$col->id}' is out of range [{$min}, {$max}]"]];
		}
		return [];
	}

	private function validateNumeric(ColumnDefinition $col, mixed $value): array
	{
		if (!is_numeric($value)) {
			return [['field' => $col->id, 'code' => 'INVALID_TYPE', 'message' => "Field '{$col->id}' must be a number"]];
		}
		return [];
	}

	private function validateBoolean(ColumnDefinition $col, mixed $value): array
	{
		if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
			return [['field' => $col->id, 'code' => 'INVALID_TYPE', 'message' => "Field '{$col->id}' must be a boolean (true/false or 0/1)"]];
		}
		return [];
	}

	private function validateDate(ColumnDefinition $col, mixed $value): array
	{
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return [['field' => $col->id, 'code' => 'INVALID_FORMAT', 'message' => "Field '{$col->id}' must be a date in format YYYY-MM-DD"]];
		}
		$parts = explode('-', $value);
		if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
			return [['field' => $col->id, 'code' => 'INVALID_DATE', 'message' => "Field '{$col->id}' contains an invalid date"]];
		}
		return [];
	}

	private function validateDatetime(ColumnDefinition $col, mixed $value): array
	{
		if (!is_string($value)) {
			return [['field' => $col->id, 'code' => 'INVALID_FORMAT', 'message' => "Field '{$col->id}' must be a datetime string"]];
		}
		// Accept YYYY-MM-DD HH:MM:SS and ISO 8601
		if (
			!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) &&
			strtotime($value) === false
		) {
			return [['field' => $col->id, 'code' => 'INVALID_FORMAT', 'message' => "Field '{$col->id}' must be a datetime (YYYY-MM-DD HH:MM:SS or ISO 8601)"]];
		}
		if (strtotime($value) === false) {
			return [['field' => $col->id, 'code' => 'INVALID_DATETIME', 'message' => "Field '{$col->id}' contains an invalid datetime"]];
		}
		return [];
	}

	private function validateTime(ColumnDefinition $col, mixed $value): array
	{
		if (!is_string($value) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
			return [['field' => $col->id, 'code' => 'INVALID_FORMAT', 'message' => "Field '{$col->id}' must be a time in format HH:MM:SS"]];
		}
		return [];
	}

	private function validateJson(ColumnDefinition $col, mixed $value): array
	{
		if (is_array($value) || is_object($value)) {
			return []; // Already parsed JSON
		}
		if (!is_string($value)) {
			return [['field' => $col->id, 'code' => 'INVALID_TYPE', 'message' => "Field '{$col->id}' must be a valid JSON value"]];
		}
		json_decode($value);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return [['field' => $col->id, 'code' => 'INVALID_JSON', 'message' => "Field '{$col->id}' must contain valid JSON"]];
		}
		return [];
	}

	private function validateEnum(ColumnDefinition $col, mixed $value, ?ConfigRuntime $config): array
	{
		if ($config === null || $col->cfgItem === null) {
			return []; // Cannot validate without config
		}
		$cfgData = $config->cfgItem($col->cfgItem);
		if (!is_array($cfgData)) {
			return [];
		}
		// cfgItem je mapa klíč → objekt (např. {"0": {...}, "1": {...}})
		// Porovnáváme hodnotu proti klíčům, s přetypováním dle typu sloupce
		$strValue = (string) $value;
		if (!array_key_exists($strValue, $cfgData)) {
			return [['field' => $col->id, 'code' => 'INVALID_ENUM', 'message' => "Field '{$col->id}' contains an invalid value"]];
		}
		return [];
	}
}
