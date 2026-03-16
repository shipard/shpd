<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\MetaController;
use Shipard\Core\Database\TableDefinition;

class MetaControllerTest extends TestCase
{
	private MetaController $ctrl;

	protected function setUp(): void
	{
		$this->ctrl = new MetaController();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function makeTable(int $tableId, string $name, array $extraCols = [], ?string $displayPattern = null): TableDefinition
	{
		$data = [
			'tableId' => $tableId,
			'name'    => $name,
			'columns' => array_merge([
				['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
			], $extraCols),
		];
		if ($displayPattern !== null) {
			$data['displayPattern'] = $displayPattern;
		}
		return TableDefinition::fromArray($data);
	}

	private function getStatus(mixed $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	// -------------------------------------------------------------------------
	// tables()
	// -------------------------------------------------------------------------

	public function testTablesReturnsList(): void
	{
		$defs = [
			'core_system_users'    => $this->makeTable(1, 'Users'),
			'economy_docs_heads'   => $this->makeTable(10, 'Documents'),
		];

		$resp    = $this->ctrl->tables($defs, 'en');
		$payload = $resp->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertCount(2, $payload['data']);
	}

	public function testTablesAreSortedByTableId(): void
	{
		$defs = [
			'economy_docs_heads'   => $this->makeTable(10, 'Documents'),
			'core_system_users'    => $this->makeTable(1, 'Users'),
		];

		$resp = $this->ctrl->tables($defs, 'en');
		$data = $resp->getPayload()['data'];

		$this->assertSame(1,  $data[0]['tableId']);
		$this->assertSame(10, $data[1]['tableId']);
	}

	public function testTablesContainsRequiredFields(): void
	{
		$defs = ['core_system_users' => $this->makeTable(1, 'Uživatelé')];

		$resp = $this->ctrl->tables($defs, 'cs');
		$item = $resp->getPayload()['data'][0];

		$this->assertSame('core_system_users', $item['table']);
		$this->assertSame('Uživatelé', $item['name']);
		$this->assertSame(1, $item['tableId']);
		$this->assertSame('core.system', $item['module']);
	}

	public function testTablesModuleDerivation(): void
	{
		$defs = [
			'economy_docs_heads'       => $this->makeTable(10, 'Docs'),
			'economy_codebooks_items'  => $this->makeTable(20, 'Items'),
		];

		$resp = $this->ctrl->tables($defs, 'en');
		$data = $resp->getPayload()['data'];

		$byTable = array_column($data, null, 'table');
		$this->assertSame('economy.docs',      $byTable['economy_docs_heads']['module']);
		$this->assertSame('economy.codebooks', $byTable['economy_codebooks_items']['module']);
	}

	public function testTablesLocalizationUsesProvidedName(): void
	{
		// The TableDefinition already has the localized name baked in.
		// The language parameter is informational; MetaController uses the stored name directly.
		$defs = ['core_system_users' => $this->makeTable(1, 'Uživatelé')];

		$resp = $this->ctrl->tables($defs, 'cs');
		$this->assertSame('Uživatelé', $resp->getPayload()['data'][0]['name']);

		// With a different pre-localized name
		$defsEn = ['core_system_users' => $this->makeTable(1, 'Users')];
		$respEn = $this->ctrl->tables($defsEn, 'en');
		$this->assertSame('Users', $respEn->getPayload()['data'][0]['name']);
	}

	// -------------------------------------------------------------------------
	// table()
	// -------------------------------------------------------------------------

	public function testTableReturnsMetadataForExistingTable(): void
	{
		$def  = $this->makeTable(1, 'Users', [
			['id' => 'login',    'name' => 'Login',    'type' => 'varchar', 'length' => 100],
			['id' => 'is_active','name' => 'Active',   'type' => 'boolean', 'default' => 1],
		]);
		$defs = ['core_system_users' => $def];

		$resp    = $this->ctrl->table('core_system_users', $defs, 'en');
		$payload = $resp->getPayload();

		$this->assertTrue($payload['success']);
		$data = $payload['data'];
		$this->assertSame('core_system_users', $data['table']);
		$this->assertSame('Users', $data['name']);
		$this->assertSame(1, $data['tableId']);
		$this->assertSame('core.system', $data['module']);
		$this->assertArrayHasKey('columns', $data);
		$this->assertCount(3, $data['columns']); // id + login + is_active
	}

	public function testTableReturnsNonExistingTable404(): void
	{
		$resp = $this->ctrl->table('nonexistent', [], 'en');

		$this->assertSame(404, $this->getStatus($resp));
		$this->assertSame('TABLE_NOT_FOUND', $resp->getPayload()['error']['code']);
	}

	public function testTableIncludesDisplayPattern(): void
	{
		$def  = $this->makeTable(1, 'Users', [], '{login}');
		$defs = ['core_system_users' => $def];

		$resp = $this->ctrl->table('core_system_users', $defs, 'en');
		$data = $resp->getPayload()['data'];

		$this->assertArrayHasKey('displayPattern', $data);
		$this->assertSame('{login}', $data['displayPattern']);
	}

	public function testTableOmitsDisplayPatternWhenNull(): void
	{
		$def  = $this->makeTable(1, 'Users');
		$defs = ['core_system_users' => $def];

		$resp = $this->ctrl->table('core_system_users', $defs, 'en');
		$this->assertArrayNotHasKey('displayPattern', $resp->getPayload()['data']);
	}

	public function testTableColumnFields(): void
	{
		$def  = $this->makeTable(1, 'Users', [
			['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 50],
		]);
		$defs = ['core_system_users' => $def];

		$resp    = $this->ctrl->table('core_system_users', $defs, 'en');
		$columns = $resp->getPayload()['data']['columns'];

		$loginCol = null;
		foreach ($columns as $c) {
			if ($c['id'] === 'login') {
				$loginCol = $c;
			}
		}
		$this->assertNotNull($loginCol);
		$this->assertSame('login',   $loginCol['id']);
		$this->assertSame('Login',   $loginCol['name']);
		$this->assertSame('varchar', $loginCol['type']);
		$this->assertSame(50,        $loginCol['length']);
	}

	public function testTableIncludesIndexes(): void
	{
		$data = [
			'tableId' => 5,
			'name'    => 'Users',
			'columns' => [
				['id' => 'id',    'name' => 'ID',    'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 50],
			],
			'indexes' => [
				['id' => 'unq_login', 'type' => 'unique', 'columns' => [['column' => 'login']]],
			],
		];
		$def  = TableDefinition::fromArray($data);
		$defs = ['core_system_users' => $def];

		$resp = $this->ctrl->table('core_system_users', $defs, 'en');
		$d    = $resp->getPayload()['data'];

		$this->assertArrayHasKey('indexes', $d);
		$this->assertSame('unq_login', $d['indexes'][0]['id']);
		$this->assertSame('unique',    $d['indexes'][0]['type']);
		$this->assertSame(['login'],   $d['indexes'][0]['columns']);
	}
}
