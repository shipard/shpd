<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\TestCase;
use Shipard\Api\OpenApi\SpecGenerator;
use Shipard\Core\Database\TableDefinition;

class SpecGeneratorTest extends TestCase
{
	private SpecGenerator $gen;

	protected function setUp(): void
	{
		$this->gen = new SpecGenerator();
	}

	private function makeTable(int $id, string $name, array $extraCols = []): TableDefinition
	{
		return TableDefinition::fromArray([
			'tableId' => $id,
			'name'    => $name,
			'columns' => array_merge([
				['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
			], $extraCols),
		]);
	}

	private function spec(array $defs = [], string $baseUrl = 'https://demo.shipard.cz'): array
	{
		return $this->gen->generate($defs, $baseUrl);
	}

	// -------------------------------------------------------------------------
	// Top-level OpenAPI 3.1 structure
	// -------------------------------------------------------------------------

	public function testSpecHasOpenApiVersion(): void
	{
		$spec = $this->spec();
		$this->assertSame('3.1.0', $spec['openapi']);
	}

	public function testSpecHasInfoBlock(): void
	{
		$spec = $this->spec();
		$this->assertArrayHasKey('info', $spec);
		$this->assertArrayHasKey('title', $spec['info']);
		$this->assertArrayHasKey('version', $spec['info']);
	}

	public function testSpecHasServers(): void
	{
		$spec = $this->spec([], 'https://demo.shipard.cz');
		$this->assertArrayHasKey('servers', $spec);
		$this->assertNotEmpty($spec['servers']);
		$this->assertStringContainsString('demo.shipard.cz', $spec['servers'][0]['url']);
	}

	public function testSpecHasPaths(): void
	{
		$spec = $this->spec();
		$this->assertArrayHasKey('paths', $spec);
	}

	public function testSpecHasComponents(): void
	{
		$spec = $this->spec();
		$this->assertArrayHasKey('components', $spec);
		$this->assertArrayHasKey('schemas',         $spec['components']);
		$this->assertArrayHasKey('securitySchemes',  $spec['components']);
	}

	public function testSpecHasBearerAuthScheme(): void
	{
		$spec = $this->spec();
		$this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
		$this->assertSame('http',   $spec['components']['securitySchemes']['bearerAuth']['type']);
		$this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
	}

	public function testSpecHasGlobalSecurity(): void
	{
		$spec = $this->spec();
		$this->assertArrayHasKey('security', $spec);
	}

	// -------------------------------------------------------------------------
	// Fixed paths (auth, meta, openapi)
	// -------------------------------------------------------------------------

	public function testSpecIncludesAuthPaths(): void
	{
		$paths = $this->spec()['paths'];
		$this->assertArrayHasKey('/_auth/login',   $paths);
		$this->assertArrayHasKey('/_auth/refresh', $paths);
		$this->assertArrayHasKey('/_auth/logout',  $paths);
	}

	public function testSpecIncludesMetaPaths(): void
	{
		$paths = $this->spec()['paths'];
		$this->assertArrayHasKey('/_meta/tables',        $paths);
		$this->assertArrayHasKey('/_meta/tables/{table}', $paths);
	}

	public function testSpecIncludesOpenApiPath(): void
	{
		$this->assertArrayHasKey('/_openapi.json', $this->spec()['paths']);
	}

	// -------------------------------------------------------------------------
	// Per-table paths
	// -------------------------------------------------------------------------

	public function testSpecGeneratesAllSixCrudPathsPerTable(): void
	{
		$defs = ['core_system_users' => $this->makeTable(1, 'Users')];
		$spec = $this->spec($defs);

		$this->assertArrayHasKey('/core_system_users',      $spec['paths']);
		$this->assertArrayHasKey('/core_system_users/{id}', $spec['paths']);

		$list   = $spec['paths']['/core_system_users'];
		$detail = $spec['paths']['/core_system_users/{id}'];

		$this->assertArrayHasKey('get',  $list);
		$this->assertArrayHasKey('post', $list);
		$this->assertArrayHasKey('get',    $detail);
		$this->assertArrayHasKey('put',    $detail);
		$this->assertArrayHasKey('patch',  $detail);
		$this->assertArrayHasKey('delete', $detail);
	}

	public function testListPathHasPaginationParameters(): void
	{
		$defs  = ['core_system_users' => $this->makeTable(1, 'Users')];
		$spec  = $this->spec($defs);
		$params = $spec['paths']['/core_system_users']['get']['parameters'];

		$names = array_column($params, 'name');
		$this->assertContains('limit',  $names);
		$this->assertContains('offset', $names);
		$this->assertContains('sort',   $names);
		$this->assertContains('fields', $names);
	}

	// -------------------------------------------------------------------------
	// Column type mapping
	// -------------------------------------------------------------------------

	private function colSpec(string $type, array $extra = []): array
	{
		$defs = ['test_table_items' => $this->makeTable(1, 'Items', [
			array_merge(['id' => 'col', 'name' => 'Col', 'type' => $type, 'nullable' => true], $extra),
		])];
		$spec = $this->spec($defs);
		return $spec['components']['schemas']['test_table_items_item']['properties']['col'];
	}

	public function testIntColumnMapsToInteger(): void
	{
		$this->assertSame('integer', $this->colSpec('int')['type']);
	}

	public function testSmallintColumnMapsToInteger(): void
	{
		$this->assertSame('integer', $this->colSpec('smallint')['type']);
	}

	public function testBigintColumnMapsToInteger(): void
	{
		$this->assertSame('integer', $this->colSpec('bigint')['type']);
	}

	public function testNumericColumnMapsToNumber(): void
	{
		$this->assertSame('number', $this->colSpec('numeric', ['precision' => 10, 'scale' => 2])['type']);
	}

	public function testFloatColumnMapsToNumber(): void
	{
		$this->assertSame('number', $this->colSpec('float')['type']);
	}

	public function testBooleanColumnMapsToBoolean(): void
	{
		$this->assertSame('boolean', $this->colSpec('boolean')['type']);
	}

	public function testVarcharColumnMapsToStringWithMaxLength(): void
	{
		$schema = $this->colSpec('varchar', ['length' => 100]);
		$this->assertSame('string', $schema['type']);
		$this->assertSame(100, $schema['maxLength']);
	}

	public function testDateColumnMapsToStringDate(): void
	{
		$schema = $this->colSpec('date');
		$this->assertSame('string', $schema['type']);
		$this->assertSame('date',   $schema['format']);
	}

	public function testDatetimeColumnMapsToStringDatetime(): void
	{
		$schema = $this->colSpec('datetime');
		$this->assertSame('string',    $schema['type']);
		$this->assertSame('date-time', $schema['format']);
	}

	public function testTextColumnMapsToString(): void
	{
		$this->assertSame('string', $this->colSpec('text')['type']);
	}

	public function testJsonColumnMapsToObject(): void
	{
		$this->assertSame('object', $this->colSpec('json', ['nullable' => true])['type']);
	}

	// -------------------------------------------------------------------------
	// Schemas
	// -------------------------------------------------------------------------

	public function testItemSchemaContainsAllColumns(): void
	{
		$defs = ['core_system_users' => $this->makeTable(1, 'Users', [
			['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 50],
		])];
		$spec       = $this->spec($defs);
		$properties = $spec['components']['schemas']['core_system_users_item']['properties'];

		$this->assertArrayHasKey('id',    $properties);
		$this->assertArrayHasKey('login', $properties);
	}

	public function testCreateSchemaOmitsReadOnlyColumns(): void
	{
		$defs = ['core_system_users' => $this->makeTable(1, 'Users', [
			['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 50],
		])];
		$spec       = $this->spec($defs);
		$properties = $spec['components']['schemas']['core_system_users_create']['properties'];

		$this->assertArrayNotHasKey('id', $properties);
		$this->assertArrayHasKey('login', $properties);
	}

	public function testListResponseSchemaHasMeta(): void
	{
		$defs   = ['core_system_users' => $this->makeTable(1, 'Users')];
		$spec   = $this->spec($defs);
		$schema = $spec['components']['schemas']['core_system_users_list_response'];

		$this->assertSame('object', $schema['type']);
		$this->assertArrayHasKey('data', $schema['properties']);
		$this->assertArrayHasKey('meta', $schema['properties']);
	}

	public function testFixedSchemasPresent(): void
	{
		$schemas = $this->spec()['components']['schemas'];
		$this->assertArrayHasKey('ErrorResponse',  $schemas);
		$this->assertArrayHasKey('LoginRequest',   $schemas);
		$this->assertArrayHasKey('LoginResponse',  $schemas);
	}

	public function testMultipleTablesGenerateMultipleSchemaSets(): void
	{
		$defs = [
			'core_system_users'  => $this->makeTable(1, 'Users'),
			'economy_docs_heads' => $this->makeTable(10, 'Docs'),
		];
		$schemas = $this->spec($defs)['components']['schemas'];

		$this->assertArrayHasKey('core_system_users_item',            $schemas);
		$this->assertArrayHasKey('economy_docs_heads_item',           $schemas);
		$this->assertArrayHasKey('core_system_users_list_response',   $schemas);
		$this->assertArrayHasKey('economy_docs_heads_list_response',  $schemas);
	}
}
