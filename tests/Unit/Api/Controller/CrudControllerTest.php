<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\CrudController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

/**
 * In-memory CrudController for testing.
 * Overrides protected DB methods to use arrays instead of a real database.
 */
class TestableCrudController extends CrudController
{
	/** @var array<string, array<int, array<string,mixed>>> table => [id => row] */
	private array $store = [];
	private int $nextId = 1;

	public function seed(string $table, array $rows): void
	{
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? $this->nextId++);
			$this->store[$table][$id] = array_merge(['id' => $id], $row);
		}
	}

	protected function fetchList(string $tableName, array $columns, array $filters, array $sorts, int $limit, int $offset): array
	{
		$rows = array_values($this->store[$tableName] ?? []);
		$rows = $this->applyFilters($rows, $filters);
		$rows = $this->applySorts($rows, $sorts);
		$rows = array_slice($rows, $offset, $limit);
		return $this->selectColumns($rows, $columns);
	}

	protected function countList(string $tableName, array $filters): int
	{
		$rows = array_values($this->store[$tableName] ?? []);
		return count($this->applyFilters($rows, $filters));
	}

	protected function fetchById(string $tableName, int $id, array $columns): ?array
	{
		$row = $this->store[$tableName][$id] ?? null;
		if ($row === null) {
			return null;
		}
		if ($columns === ['id']) {
			return ['id' => $row['id']];
		}
		return $this->selectColumns([$row], $columns)[0] ?? null;
	}

	protected function insertRecord(string $tableName, array $data): int
	{
		$id = $this->nextId++;
		$this->store[$tableName][$id] = array_merge(['id' => $id], $data);
		return $id;
	}

	protected function updateRecord(string $tableName, int $id, array $data): void
	{
		if (isset($this->store[$tableName][$id])) {
			$this->store[$tableName][$id] = array_merge($this->store[$tableName][$id], $data);
		}
	}

	protected function deleteRecord(string $tableName, int $id): void
	{
		unset($this->store[$tableName][$id]);
	}

	private function applyFilters(array $rows, array $filters): array
	{
		foreach ($filters as $f) {
			$rows = array_filter($rows, function ($row) use ($f) {
				$val = $row[$f['column']] ?? null;
				return match ($f['operator']) {
					'eq'  => (string) $val === $f['value'],
					'neq' => (string) $val !== $f['value'],
					'gt'  => $val > $f['value'],
					'gte' => $val >= $f['value'],
					'lt'  => $val < $f['value'],
					'lte' => $val <= $f['value'],
					'like' => str_contains((string) $val, $f['value']),
					'in'  => in_array((string) $val, array_map('trim', explode(',', $f['value'])), true),
					'null' => $f['value'] === 'true' ? $val === null : $val !== null,
					'notnull' => $val !== null,
					default => true,
				};
			});
		}
		return array_values($rows);
	}

	private function applySorts(array $rows, array $sorts): array
	{
		if ($sorts === []) {
			usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
			return $rows;
		}
		usort($rows, function ($a, $b) use ($sorts) {
			foreach ($sorts as $s) {
				$cmp = $a[$s['column']] <=> $b[$s['column']];
				if ($s['direction'] === 'desc') {
					$cmp = -$cmp;
				}
				if ($cmp !== 0) {
					return $cmp;
				}
			}
			return 0;
		});
		return $rows;
	}

	private function selectColumns(array $rows, array $columns): array
	{
		if ($columns === []) {
			return $rows;
		}
		$flip = array_flip($columns);
		return array_map(fn($row) => array_intersect_key($row, $flip), $rows);
	}
}

// ============================================================================
// Actual test class
// ============================================================================

class CrudControllerTest extends TestCase
{
	private DataSourceConnection $db;

	protected function setUp(): void
	{
		$ref      = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function makeTable(string $name, array $extraCols = []): TableDefinition
	{
		$baseCols = [
			['id' => 'id',       'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
			['id' => 'name',     'name' => 'Name', 'type' => 'varchar', 'length' => 100],
			['id' => 'created',  'name' => 'Created', 'type' => 'datetime', 'nullable' => true],
			['id' => 'modified', 'name' => 'Modified', 'type' => 'datetime', 'nullable' => true],
		];
		return TableDefinition::fromArray([
			'tableId' => 1,
			'name'    => $name,
			'columns' => array_merge($baseCols, $extraCols),
		]);
	}

	private function ctrl(array $tables, array $seed = [], ?AuthContext $auth = null): TestableCrudController
	{
		$c = new TestableCrudController($this->db, $tables, null, $auth ?? new AuthContext(false));
		foreach ($seed as $table => $rows) {
			$c->seed($table, $rows);
		}
		return $c;
	}

	private function adminAuth(): AuthContext
	{
		return new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true);
	}

	private function userAuth(): AuthContext
	{
		return new AuthContext(true, 2, 'session', 'shpd_st_y', isAdmin: false);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	private function req(string $method = 'GET', string $path = '/api/v1/items', array $queryParams = [], string $body = ''): Request
	{
		return Request::fromArray($method, $path, $queryParams, $body, []);
	}

	// -------------------------------------------------------------------------
	// TABLE_NOT_FOUND
	// -------------------------------------------------------------------------

	public function testUnknownTableReturns404TableNotFound(): void
	{
		$ctrl = $this->ctrl([]);
		$resp = $ctrl->list('nonexistent', $this->req());
		$this->assertSame('TABLE_NOT_FOUND', $resp->getPayload()['error']['code']);
	}

	// -------------------------------------------------------------------------
	// list
	// -------------------------------------------------------------------------

	public function testListReturnsAllRows(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [
			['id' => 1, 'name' => 'Alpha'],
			['id' => 2, 'name' => 'Beta'],
		]]);

		$resp    = $ctrl->list('items', $this->req());
		$payload = $resp->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertCount(2, $payload['data']);
		$this->assertSame(2, $payload['meta']['total']);
	}

	public function testListPagination(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [
			['id' => 1, 'name' => 'A'],
			['id' => 2, 'name' => 'B'],
			['id' => 3, 'name' => 'C'],
		]]);

		$resp = $ctrl->list('items', $this->req(queryParams: ['limit' => '2', 'offset' => '1']));
		$payload = $resp->getPayload();

		$this->assertCount(2, $payload['data']);
		$this->assertSame(3, $payload['meta']['total']);
		$this->assertSame(2, $payload['meta']['limit']);
		$this->assertSame(1, $payload['meta']['offset']);
	}

	public function testListSortDescending(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [
			['id' => 1, 'name' => 'Alpha'],
			['id' => 2, 'name' => 'Zeta'],
			['id' => 3, 'name' => 'Beta'],
		]]);

		$resp  = $ctrl->list('items', $this->req(queryParams: ['sort' => 'name:desc']));
		$names = array_column($resp->getPayload()['data'], 'name');

		$this->assertSame(['Zeta', 'Beta', 'Alpha'], $names);
	}

	public function testListFilterEq(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [
			['id' => 1, 'name' => 'Alpha'],
			['id' => 2, 'name' => 'Beta'],
		]]);

		$resp    = $ctrl->list('items', $this->req(queryParams: ['filter' => ['name' => 'eq:Alpha']]));
		$payload = $resp->getPayload();

		$this->assertCount(1, $payload['data']);
		$this->assertSame('Alpha', $payload['data'][0]['name']);
		$this->assertSame(1, $payload['meta']['total']);
	}

	public function testListWithFields(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [
			['id' => 1, 'name' => 'Alpha'],
		]]);

		$resp    = $ctrl->list('items', $this->req(queryParams: ['fields' => 'id,name']));
		$payload = $resp->getPayload();

		$keys = array_keys($payload['data'][0]);
		$this->assertContains('id', $keys);
		$this->assertContains('name', $keys);
	}

	public function testListWithInvalidSortReturns400(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->list('items', $this->req(queryParams: ['sort' => 'nonexistent:asc']));
		$this->assertFalse($resp->getPayload()['success']);
		$this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
	}

	public function testListWithInvalidFilterColumnReturns400(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->list('items', $this->req(queryParams: ['filter' => ['nosuchcol' => 'eq:1']]));
		$this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
	}

	// -------------------------------------------------------------------------
	// show
	// -------------------------------------------------------------------------

	public function testShowExistingRecord(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [['id' => 7, 'name' => 'Lucky']]]);

		$resp    = $ctrl->show('items', 7, $this->req());
		$payload = $resp->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertSame('Lucky', $payload['data']['name']);
	}

	public function testShowNonExistingRecordReturns404(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->show('items', 999, $this->req());
		$this->assertSame(404, $this->getStatus($resp));
		$this->assertSame('NOT_FOUND', $resp->getPayload()['error']['code']);
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	public function testCreateWithValidDataReturns201(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->create('items', $this->req(body: '{"name":"NewItem"}'));

		$this->assertSame(201, $this->getStatus($resp));
		$this->assertTrue($resp->getPayload()['success']);
		$this->assertSame('NewItem', $resp->getPayload()['data']['name']);
	}

	public function testCreateWithMissingRequiredFieldReturns422(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->create('items', $this->req(body: '{}'));

		$this->assertSame(422, $this->getStatus($resp));
		$this->assertSame('VALIDATION_ERROR', $resp->getPayload()['error']['code']);
	}

	public function testCreateWithInvalidBodyReturns400(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->create('items', $this->req(body: 'not json'));
		$this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
	}

	// -------------------------------------------------------------------------
	// update
	// -------------------------------------------------------------------------

	public function testUpdateExistingRecord(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [['id' => 1, 'name' => 'Old']]]);

		$resp = $ctrl->update('items', 1, $this->req(body: '{"name":"New"}'));

		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame('New', $resp->getPayload()['data']['name']);
	}

	public function testUpdateNonExistingReturns404(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->update('items', 99, $this->req(body: '{"name":"X"}'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// patch
	// -------------------------------------------------------------------------

	public function testPatchUpdatesOnlySuppliedField(): void
	{
		$def  = $this->makeTable('items', [
			['id' => 'score', 'name' => 'Score', 'type' => 'int', 'nullable' => true],
		]);
		$ctrl = $this->ctrl(['items' => $def], ['items' => [['id' => 1, 'name' => 'Old', 'score' => 5]]]);

		$resp = $ctrl->patch('items', 1, $this->req(body: '{"score":10}'));

		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame('Old', $resp->getPayload()['data']['name']); // name unchanged
		$this->assertSame(10, $resp->getPayload()['data']['score']);
	}

	public function testPatchNonExistingReturns404(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->patch('items', 99, $this->req(body: '{"name":"X"}'));
		$this->assertSame(404, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// delete
	// -------------------------------------------------------------------------

	public function testDeleteExistingRecordReturns204(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [['id' => 1, 'name' => 'To delete']]]);

		$resp = $ctrl->delete('items', 1);

		$this->assertSame(204, $this->getStatus($resp));
		$this->assertTrue($resp->getPayload()['success']);

		// Verify actually deleted
		$resp2 = $ctrl->show('items', 1, $this->req());
		$this->assertSame(404, $this->getStatus($resp2));
	}

	public function testDeleteNonExistingReturns404(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def]);

		$resp = $ctrl->delete('items', 999);
		$this->assertSame(404, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// Password column filtering
	// -------------------------------------------------------------------------

	public function testPasswordColumnsAreNotReturnedInListOutput(): void
	{
		$def = $this->makeTable('users', [
			['id' => 'password_hash', 'name' => 'PW Hash', 'type' => 'varchar', 'length' => 255],
		]);
		$ctrl = $this->ctrl(['users' => $def], ['users' => [
			['id' => 1, 'name' => 'Alice', 'password_hash' => 'secret_hash'],
		]]);

		$resp = $ctrl->list('users', $this->req());
		$row  = $resp->getPayload()['data'][0];

		$this->assertArrayNotHasKey('password_hash', $row);
		$this->assertArrayHasKey('name', $row);
	}

	public function testPasswordColumnsAreNotReturnedInShowOutput(): void
	{
		$def = $this->makeTable('users', [
			['id' => 'password_hash', 'name' => 'PW Hash', 'type' => 'varchar', 'length' => 255],
		]);
		$ctrl = $this->ctrl(['users' => $def], ['users' => [
			['id' => 1, 'name' => 'Alice', 'password_hash' => 'secret_hash'],
		]]);

		$resp = $ctrl->show('users', 1, $this->req());
		$this->assertArrayNotHasKey('password_hash', $resp->getPayload()['data']);
	}

	// -------------------------------------------------------------------------
	// Type casting
	// -------------------------------------------------------------------------

	public function testIntColumnsAreCastToInt(): void
	{
		$def = TableDefinition::fromArray([
			'tableId' => 2,
			'name'    => 'things',
			'columns' => [
				['id' => 'id',    'name' => 'ID',    'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'count', 'name' => 'Count', 'type' => 'int'],
			],
		]);
		$ctrl = $this->ctrl(['things' => $def], ['things' => [['id' => 1, 'count' => '42']]]);

		$resp = $ctrl->show('things', 1, $this->req());
		$this->assertSame(42, $resp->getPayload()['data']['count']);
	}

	public function testBooleanColumnsAreCastToBool(): void
	{
		$def = TableDefinition::fromArray([
			'tableId' => 3,
			'name'    => 'flags',
			'columns' => [
				['id' => 'id',     'name' => 'ID',     'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'active', 'name' => 'Active', 'type' => 'boolean', 'default' => 1],
			],
		]);
		$ctrl = $this->ctrl(['flags' => $def], ['flags' => [['id' => 1, 'active' => '1']]]);

		$resp = $ctrl->show('flags', 1, $this->req());
		$this->assertIsBool($resp->getPayload()['data']['active']);
		$this->assertTrue($resp->getPayload()['data']['active']);
	}

	// -------------------------------------------------------------------------
	// System table guard (core_system_*)
	// -------------------------------------------------------------------------

	private function systemUsersDef(): TableDefinition
	{
		return TableDefinition::fromArray([
			'tableId' => 1,
			'name'    => 'Users',
			'columns' => [
				['id' => 'id',            'name' => 'ID',    'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'login',         'name' => 'Login', 'type' => 'varchar', 'length' => 100],
				['id' => 'password_hash', 'name' => 'Hash',  'type' => 'varchar', 'length' => 255, 'nullable' => true, 'sensitive' => true],
			],
		]);
	}

	private function systemCtrl(AuthContext $auth): TestableCrudController
	{
		return $this->ctrl(
			['core_system_users' => $this->systemUsersDef()],
			['core_system_users' => [['id' => 1, 'login' => 'admin', 'password_hash' => 'secret-hash']]],
			$auth,
		);
	}

	public function testNonAdminGetsForbiddenOnAllSystemTableActions(): void
	{
		$ctrl = $this->systemCtrl($this->userAuth());

		$responses = [
			'list'            => $ctrl->list('core_system_users', $this->req()),
			'show'            => $ctrl->show('core_system_users', 1, $this->req()),
			'create'          => $ctrl->create('core_system_users', $this->req('POST', body: '{"login":"x"}')),
			'update'          => $ctrl->update('core_system_users', 1, $this->req('PUT', body: '{"login":"x"}')),
			'patch'           => $ctrl->patch('core_system_users', 1, $this->req('PATCH', body: '{"login":"x"}')),
			'delete'          => $ctrl->delete('core_system_users', 1),
			'docStateOptions' => $ctrl->docStateOptions('core_system_users', 1),
		];

		foreach ($responses as $action => $resp) {
			$this->assertSame(403, $this->getStatus($resp), "action {$action}");
			$this->assertSame('FORBIDDEN_SYSTEM_TABLE', $resp->getPayload()['error']['code'], "action {$action}");
		}
	}

	public function testAdminPassesSystemTableGuard(): void
	{
		$ctrl = $this->systemCtrl($this->adminAuth());

		$resp = $ctrl->list('core_system_users', $this->req());
		$this->assertSame(200, $this->getStatus($resp));
		$this->assertCount(1, $resp->getPayload()['data']);
	}

	public function testUnauthenticatedDefaultContextIsBlockedOnSystemTable(): void
	{
		// Výchozí AuthContext(false) v ctrl() — guard musí držet i bez wiringu.
		$ctrl = $this->ctrl(['core_system_users' => $this->systemUsersDef()]);
		$resp = $ctrl->list('core_system_users', $this->req());
		$this->assertSame(403, $this->getStatus($resp));
	}

	public function testNonAdminUnaffectedOnRegularTable(): void
	{
		$def  = $this->makeTable('items');
		$ctrl = $this->ctrl(['items' => $def], ['items' => [['id' => 1, 'name' => 'A']]], $this->userAuth());

		$resp = $ctrl->list('items', $this->req());
		$this->assertSame(200, $this->getStatus($resp));
	}

	// -------------------------------------------------------------------------
	// Sensitive columns
	// -------------------------------------------------------------------------

	private function secretsDef(): TableDefinition
	{
		return TableDefinition::fromArray([
			'tableId' => 9,
			'name'    => 'secrets',
			'columns' => [
				['id' => 'id',       'name' => 'ID',     'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'name',     'name' => 'Name',   'type' => 'varchar', 'length' => 100],
				['id' => 'key_hash', 'name' => 'Hash',   'type' => 'varchar', 'length' => 64, 'sensitive' => true],
			],
		]);
	}

	private function secretsCtrl(): TestableCrudController
	{
		return $this->ctrl(
			['secrets' => $this->secretsDef()],
			['secrets' => [['id' => 1, 'name' => 'A', 'key_hash' => 'abc123']]],
		);
	}

	public function testSensitiveColumnMissingFromListAndShow(): void
	{
		$ctrl = $this->secretsCtrl();

		$list = $ctrl->list('secrets', $this->req())->getPayload()['data'];
		$this->assertArrayNotHasKey('key_hash', $list[0]);
		$this->assertSame('A', $list[0]['name']);

		$show = $ctrl->show('secrets', 1, $this->req())->getPayload()['data'];
		$this->assertArrayNotHasKey('key_hash', $show);
	}

	public function testSensitiveColumnCannotBeRequestedViaFields(): void
	{
		$ctrl = $this->secretsCtrl();
		$resp = $ctrl->list('secrets', $this->req(queryParams: ['fields' => 'name,key_hash']));
		$this->assertSame(400, $this->getStatus($resp));
	}

	public function testCreateWithSensitiveColumnReturns400(): void
	{
		$ctrl = $this->secretsCtrl();
		$resp = $ctrl->create('secrets', $this->req('POST', body: '{"name":"B","key_hash":"evil"}'));

		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}

	public function testPatchWithSensitiveColumnReturns400(): void
	{
		$ctrl = $this->secretsCtrl();
		$resp = $ctrl->patch('secrets', 1, $this->req('PATCH', body: '{"key_hash":"evil"}'));

		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}

	public function testFilterOnSensitiveColumnReturns400(): void
	{
		// like/gt filtr by fungoval jako orákulum na extrakci hodnoty po znacích.
		$ctrl = $this->secretsCtrl();
		$resp = $ctrl->list('secrets', $this->req(queryParams: ['filter' => ['key_hash' => 'like:abc']]));

		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}

	public function testSortOnSensitiveColumnReturns400(): void
	{
		$ctrl = $this->secretsCtrl();
		$resp = $ctrl->list('secrets', $this->req(queryParams: ['sort' => 'key_hash']));

		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}
}
