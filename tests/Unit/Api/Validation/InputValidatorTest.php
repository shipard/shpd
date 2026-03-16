<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Validation;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Validation\InputValidator;
use Shipard\Core\Database\TableDefinition;

class InputValidatorTest extends TestCase
{
	private InputValidator $v;

	protected function setUp(): void
	{
		$this->v = new InputValidator();
	}

	// -------------------------------------------------------------------------
	// Helper: build minimal table definitions
	// -------------------------------------------------------------------------

	private function makeTable(array $columns): TableDefinition
	{
		$cols = array_merge([
			['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
		], $columns);
		return TableDefinition::fromArray(['tableId' => 1, 'name' => 'Test', 'columns' => $cols]);
	}

	private function errors(array $errors): array
	{
		return array_column($errors, 'code', 'field');
	}

	// -------------------------------------------------------------------------
	// Required fields (create mode)
	// -------------------------------------------------------------------------

	public function testRequiredFieldMissingInCreateMode(): void
	{
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
		]);

		$errors = $this->v->validate([], $table, 'create');

		$this->assertNotEmpty($errors);
		$this->assertSame('REQUIRED', $this->errors($errors)['name']);
	}

	public function testOptionalFieldMissingInCreateModeIsOk(): void
	{
		$table = $this->makeTable([
			['id' => 'note', 'name' => 'Note', 'type' => 'varchar', 'length' => 200, 'nullable' => true],
		]);

		$errors = $this->v->validate([], $table, 'create');
		$this->assertEmpty($errors);
	}

	public function testFieldWithDefaultIsNotRequiredInCreateMode(): void
	{
		$table = $this->makeTable([
			['id' => 'status', 'name' => 'Status', 'type' => 'int', 'default' => 1],
		]);

		$errors = $this->v->validate([], $table, 'create');
		$this->assertEmpty($errors);
	}

	public function testAutoFieldsAreNeverRequired(): void
	{
		// Even with 'created' and 'modified' as non-nullable, they should be ignored
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
		]);

		$errors = $this->v->validate(['name' => 'Test'], $table, 'create');
		$this->assertEmpty($errors);
	}

	public function testRequiredFieldPresentInCreateModeIsOk(): void
	{
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
		]);

		$errors = $this->v->validate(['name' => 'Alice'], $table, 'create');
		$this->assertEmpty($errors);
	}

	// -------------------------------------------------------------------------
	// Patch mode: missing required fields are OK
	// -------------------------------------------------------------------------

	public function testMissingRequiredFieldInPatchModeIsOk(): void
	{
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
			['id' => 'email', 'name' => 'Email', 'type' => 'varchar', 'length' => 200, 'nullable' => true],
		]);

		$errors = $this->v->validate(['email' => 'test@example.com'], $table, 'patch');
		$this->assertEmpty($errors);
	}

	// -------------------------------------------------------------------------
	// Unknown fields: silently ignored
	// -------------------------------------------------------------------------

	public function testUnknownFieldInInputIsIgnored(): void
	{
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
		]);

		$errors = $this->v->validate(['name' => 'Alice', 'nonexistent_field' => 'ignored'], $table, 'create');
		$this->assertEmpty($errors);
	}

	// -------------------------------------------------------------------------
	// VARCHAR length
	// -------------------------------------------------------------------------

	public function testVarcharWithinLengthIsOk(): void
	{
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 10],
		]);

		$errors = $this->v->validate(['name' => 'short'], $table, 'patch');
		$this->assertEmpty($errors);
	}

	public function testVarcharExceedsLengthIsError(): void
	{
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 5],
		]);

		$errors = $this->v->validate(['name' => 'toolongvalue'], $table, 'patch');
		$this->assertNotEmpty($errors);
		$this->assertSame('TOO_LONG', $this->errors($errors)['name']);
	}

	// -------------------------------------------------------------------------
	// Integer types
	// -------------------------------------------------------------------------

	public function testValidIntIsOk(): void
	{
		$table = $this->makeTable([['id' => 'count', 'name' => 'Count', 'type' => 'int']]);
		$this->assertEmpty($this->v->validate(['count' => 42], $table, 'patch'));
	}

	public function testInvalidIntIsError(): void
	{
		$table = $this->makeTable([['id' => 'count', 'name' => 'Count', 'type' => 'int']]);
		$errors = $this->v->validate(['count' => 'not-a-number'], $table, 'patch');
		$this->assertSame('INVALID_TYPE', $this->errors($errors)['count']);
	}

	public function testSmallintOutOfRangeIsError(): void
	{
		$table = $this->makeTable([['id' => 'val', 'name' => 'Val', 'type' => 'smallint']]);
		$errors = $this->v->validate(['val' => 40000], $table, 'patch');
		$this->assertSame('OUT_OF_RANGE', $this->errors($errors)['val']);
	}

	// -------------------------------------------------------------------------
	// Boolean
	// -------------------------------------------------------------------------

	public function testValidBoolIsOk(): void
	{
		$table = $this->makeTable([['id' => 'active', 'name' => 'Active', 'type' => 'boolean']]);
		$this->assertEmpty($this->v->validate(['active' => true], $table, 'patch'));
		$this->assertEmpty($this->v->validate(['active' => 0], $table, 'patch'));
		$this->assertEmpty($this->v->validate(['active' => 1], $table, 'patch'));
	}

	public function testInvalidBoolIsError(): void
	{
		$table = $this->makeTable([['id' => 'active', 'name' => 'Active', 'type' => 'boolean']]);
		$errors = $this->v->validate(['active' => 'yes'], $table, 'patch');
		$this->assertSame('INVALID_TYPE', $this->errors($errors)['active']);
	}

	// -------------------------------------------------------------------------
	// Date
	// -------------------------------------------------------------------------

	public function testValidDateIsOk(): void
	{
		$table = $this->makeTable([['id' => 'day', 'name' => 'Day', 'type' => 'date']]);
		$this->assertEmpty($this->v->validate(['day' => '2026-03-16'], $table, 'patch'));
	}

	public function testInvalidDateIsError(): void
	{
		$table = $this->makeTable([['id' => 'day', 'name' => 'Day', 'type' => 'date']]);
		$errors = $this->v->validate(['day' => '16-03-2026'], $table, 'patch');
		$this->assertSame('INVALID_FORMAT', $this->errors($errors)['day']);
	}

	// -------------------------------------------------------------------------
	// Datetime
	// -------------------------------------------------------------------------

	public function testValidDatetimeIsOk(): void
	{
		$table = $this->makeTable([['id' => 'ts', 'name' => 'TS', 'type' => 'datetime']]);
		$this->assertEmpty($this->v->validate(['ts' => '2026-03-16 14:30:00'], $table, 'patch'));
	}

	public function testInvalidDatetimeIsError(): void
	{
		$table = $this->makeTable([['id' => 'ts', 'name' => 'TS', 'type' => 'datetime']]);
		$errors = $this->v->validate(['ts' => 'not-a-date'], $table, 'patch');
		$this->assertNotEmpty($errors);
		$this->assertArrayHasKey('ts', $this->errors($errors));
	}

	// -------------------------------------------------------------------------
	// JSON
	// -------------------------------------------------------------------------

	public function testValidJsonStringIsOk(): void
	{
		$table = $this->makeTable([['id' => 'meta', 'name' => 'Meta', 'type' => 'json', 'nullable' => true]]);
		$this->assertEmpty($this->v->validate(['meta' => '{"key":"value"}'], $table, 'patch'));
	}

	public function testValidJsonArrayIsOk(): void
	{
		$table = $this->makeTable([['id' => 'meta', 'name' => 'Meta', 'type' => 'json', 'nullable' => true]]);
		$this->assertEmpty($this->v->validate(['meta' => ['key' => 'value']], $table, 'patch'));
	}

	public function testInvalidJsonStringIsError(): void
	{
		$table = $this->makeTable([['id' => 'meta', 'name' => 'Meta', 'type' => 'json', 'nullable' => true]]);
		$errors = $this->v->validate(['meta' => '{bad json}'], $table, 'patch');
		$this->assertSame('INVALID_JSON', $this->errors($errors)['meta']);
	}

	// -------------------------------------------------------------------------
	// Nullable null values
	// -------------------------------------------------------------------------

	public function testNullValueForNullableFieldIsOk(): void
	{
		$table = $this->makeTable([
			['id' => 'note', 'name' => 'Note', 'type' => 'varchar', 'length' => 200, 'nullable' => true],
		]);
		$errors = $this->v->validate(['note' => null], $table, 'patch');
		$this->assertEmpty($errors);
	}

	public function testNullValueForNonNullableFieldIsError(): void
	{
		$table = $this->makeTable([
			['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
		]);
		$errors = $this->v->validate(['name' => null], $table, 'patch');
		$this->assertSame('NULL_NOT_ALLOWED', $this->errors($errors)['name']);
	}
}
