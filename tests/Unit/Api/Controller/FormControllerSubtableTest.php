<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\FormController;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormRegistry;
use Shipard\Tests\Fixtures\Core\Form\StubSubtableParentForm;

/**
 * GET /_ui/form/{table}/subtable/{tabId}/{parentId} — načtení rodiče,
 * nalezení tabu, řazení dětských řádků, guardy, 404 větve.
 */
class FormControllerSubtableTest extends TestCase
{
	private FormController $ctrl;

	protected function setUp(): void
	{
		$this->ctrl = new FormController();
	}

	private function tables(bool $childAdminOnly = false): array
	{
		return [
			'parent_tbl' => TableDefinition::fromArray([
				'tableId' => 500,
				'name'    => 'Parent',
				'columns' => [
					['id' => 'id',   'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
				],
			]),
			'child_tbl' => TableDefinition::fromArray([
				'tableId'   => 501,
				'name'      => 'Child',
				'adminOnly' => $childAdminOnly,
				'columns'   => [
					['id' => 'id',     'name' => 'ID',     'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'parent', 'name' => 'Parent', 'type' => 'int', 'reference' => 'parent_tbl'],
					['id' => 'name',   'name' => 'Name',   'type' => 'varchar', 'length' => 100],
					['id' => 'amount', 'name' => 'Amount', 'type' => 'numeric', 'precision' => 12, 'scale' => 2],
					['id' => 'token',  'name' => 'Token',  'type' => 'varchar', 'length' => 64, 'sensitive' => true],
				],
			]),
		];
	}

	private function registry(): FormRegistry
	{
		return new FormRegistry([
			['table' => 'parent_tbl', 'class' => StubSubtableParentForm::class],
		]);
	}

	/** @param list<array<string, mixed>> $childRows */
	private function db(?array $parentRow, array $childRows = [], ?string &$capturedSql = null): DataSourceConnection
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchRow')->willReturn($parentRow);
		$db->method('fetchAll')->willReturnCallback(
			static function (mixed ...$args) use ($childRows, &$capturedSql): array {
				$capturedSql = (string) ($args[0] ?? '');
				return $childRows;
			},
		);
		return $db;
	}

	private function httpStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	private function admin(): AuthContext
	{
		return new AuthContext(true, 1, 'session', null, true);
	}

	private function nonAdmin(): AuthContext
	{
		return new AuthContext(true, 2, 'session', null, false);
	}

	// ── Happy path ───────────────────────────────────────────────────────────

	public function testReturnsColumnsAndRenderedRowsOrderedBySortWithIdTiebreaker(): void
	{
		$sql = null;
		$db = $this->db(
			['id' => 5, 'name' => 'Rodič'],
			[
				['id' => 11, 'parent' => 5, 'name' => 'Beta',  'amount' => '20.00', 'token' => 'tajne'],
				['id' => 10, 'parent' => 5, 'name' => 'Alfa',  'amount' => null,    'token' => 'tajne'],
			],
			$sql,
		);

		$res = $this->ctrl->subtable('parent_tbl', 'items', 5, $this->tables(), $db, $this->registry(), null, $this->admin());

		$this->assertSame(200, $this->httpStatus($res));
		$data = $res->getPayload()['data'];
		$this->assertSame(['name', 'amount'], array_column($data['columns'], 'id'));
		$this->assertNull($data['order_column']);
		$this->assertCount(2, $data['rows']);
		$this->assertSame(11, $data['rows'][0]['id']);
		$this->assertSame(['name' => 'Beta', 'amount' => '20,00'], $data['rows'][0]['cells']);
		$this->assertSame(['name' => 'Alfa'], $data['rows'][1]['cells']);
		// sensitive sloupec nikdy neopustí server — ani v cells, ani ve sloupcích
		$this->assertNotContains('token', array_column($data['columns'], 'id'));

		$this->assertStringContainsString('FROM `child_tbl` WHERE `parent` = %i', $sql);
		$this->assertStringContainsString('ORDER BY `name` DESC, `id` ASC', $sql);
	}

	// ── 404 branches ─────────────────────────────────────────────────────────

	public function testUnknownParentTableIs404(): void
	{
		$res = $this->ctrl->subtable('nope', 'items', 5, $this->tables(), $this->db(null), $this->registry(), null, $this->admin());
		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('TABLE_NOT_FOUND', $res->getPayload()['error']['code']);
	}

	public function testMissingParentRecordIs404(): void
	{
		$res = $this->ctrl->subtable('parent_tbl', 'items', 99, $this->tables(), $this->db(null), $this->registry(), null, $this->admin());
		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('RECORD_NOT_FOUND', $res->getPayload()['error']['code']);
	}

	public function testParentWithoutFormClassIs404(): void
	{
		$db = $this->db(['id' => 5, 'name' => 'Rodič']);
		$res = $this->ctrl->subtable('parent_tbl', 'items', 5, $this->tables(), $db, new FormRegistry(), null, $this->admin());
		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('SUBTABLE_NOT_FOUND', $res->getPayload()['error']['code']);
	}

	public function testUnknownTabIs404(): void
	{
		$db = $this->db(['id' => 5, 'name' => 'Rodič']);
		$res = $this->ctrl->subtable('parent_tbl', 'basic', 5, $this->tables(), $db, $this->registry(), null, $this->admin());
		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('SUBTABLE_NOT_FOUND', $res->getPayload()['error']['code']);
	}

	public function testInvalidSortInFormDefinitionIs500(): void
	{
		$db = $this->db(['id' => 5, 'name' => 'Rodič']);
		$res = $this->ctrl->subtable('parent_tbl', 'badsort', 5, $this->tables(), $db, $this->registry(), null, $this->admin());
		$this->assertSame(500, $this->httpStatus($res));
		$this->assertSame('INTERNAL_ERROR', $res->getPayload()['error']['code']);
	}

	// ── Guards ───────────────────────────────────────────────────────────────

	public function testNonAdminIsBlockedByChildTableGuard(): void
	{
		$db = $this->db(['id' => 5, 'name' => 'Rodič'], [['id' => 1, 'parent' => 5, 'name' => 'x']]);
		$res = $this->ctrl->subtable('parent_tbl', 'items', 5, $this->tables(childAdminOnly: true), $db, $this->registry(), null, $this->nonAdmin());
		$this->assertSame(403, $this->httpStatus($res));
		$this->assertSame('FORBIDDEN_ADMIN_ONLY', $res->getPayload()['error']['code']);
	}

	public function testAdminPassesChildTableGuard(): void
	{
		$db = $this->db(['id' => 5, 'name' => 'Rodič'], []);
		$res = $this->ctrl->subtable('parent_tbl', 'items', 5, $this->tables(childAdminOnly: true), $db, $this->registry(), null, $this->admin());
		$this->assertSame(200, $this->httpStatus($res));
	}
}
