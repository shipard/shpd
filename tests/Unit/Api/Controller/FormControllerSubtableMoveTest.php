<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\FormController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormRegistry;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Fixtures\Core\Form\StubSubtableParentForm;

/**
 * POST /_ui/form/{table}/subtable/{tabId}/{parentId}/move — přečíslování
 * skupiny 1..N + prohození sousedů v transakci, hrany, 404 cizí řádek,
 * 422 read-only rodič, 400 tab bez pořadí / špatné tělo.
 */
class FormControllerSubtableMoveTest extends TestCase
{
	private FormController $ctrl;

	protected function setUp(): void
	{
		$this->ctrl = new FormController();
	}

	private function tables(bool $parentDocStates = false): array
	{
		$parent = [
			'tableId' => 500,
			'name'    => 'Parent',
			'columns' => [
				['id' => 'id',   'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
				['id' => 'docState', 'name' => 'State', 'type' => 'tinyint', 'default' => 10, 'system' => true],
				['id' => 'docStateMain', 'name' => 'State main', 'type' => 'tinyint', 'default' => 1, 'system' => true],
			],
		];
		if ($parentDocStates) {
			$parent['docStates'] = ['stateColumn' => 'docState', 'mainColumn' => 'docStateMain', 'cfgItem' => 'test.states'];
		}
		return [
			'parent_tbl' => TableDefinition::fromArray($parent),
			'child_tbl'  => TableDefinition::fromArray([
				'tableId' => 501,
				'name'    => 'Child',
				'columns' => [
					['id' => 'id',        'name' => 'ID',     'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'parent',    'name' => 'Parent', 'type' => 'int', 'reference' => 'parent_tbl'],
					['id' => 'name',      'name' => 'Name',   'type' => 'varchar', 'length' => 100],
					['id' => 'order_pos', 'name' => 'Order',  'type' => 'smallint', 'default' => 0],
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

	/**
	 * @param list<array{id: int, pos: int}> $group řádky skupiny v pořadí, jak je vrátí SELECT … FOR UPDATE
	 * @param list<array{0: string, 1: array}> $log  zachycená volání: ['begin'|'commit'|'rollback'|'execute'|'select', args]
	 */
	private function db(?array $parentRow, array $group, array &$log): DataSourceConnection
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchRow')->willReturn($parentRow);
		$db->method('fetchAll')->willReturnCallback(
			static function (mixed ...$args) use ($group, &$log): array {
				$log[] = ['select', $args];
				return $group;
			},
		);
		$db->method('execute')->willReturnCallback(
			static function (mixed ...$args) use (&$log): void {
				$log[] = ['execute', $args];
			},
		);
		$db->method('begin')->willReturnCallback(static function () use (&$log): void { $log[] = ['begin', []]; });
		$db->method('commit')->willReturnCallback(static function () use (&$log): void { $log[] = ['commit', []]; });
		$db->method('rollback')->willReturnCallback(static function () use (&$log): void { $log[] = ['rollback', []]; });
		return $db;
	}

	private function req(array $body): Request
	{
		return Request::fromArray('POST', '/_ui/form/parent_tbl/subtable/ordered/5/move', [], json_encode($body), ['Content-Type' => 'application/json']);
	}

	private function httpStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		return $ref->getProperty('status')->getValue($response);
	}

	private function admin(): AuthContext
	{
		return new AuthContext(true, 1, 'session', null, true);
	}

	/** @return list<array{int, int}> [pos, id] z UPDATE volání */
	private function updates(array $log): array
	{
		$out = [];
		foreach ($log as [$kind, $args]) {
			if ($kind === 'execute') {
				$this->assertStringContainsString('UPDATE `child_tbl` SET `order_pos` = %i WHERE `id` = %i', $args[0]);
				$out[] = [$args[1], $args[2]];
			}
		}
		return $out;
	}

	private function kinds(array $log): array
	{
		return array_column($log, 0);
	}

	// ── Happy paths ──────────────────────────────────────────────────────────

	public function testDownOnFirstRowWithAllZerosRenumbersAndSwaps(): void
	{
		$log = [];
		// historicky všechny 0 → SELECT řadí order_pos ASC, id ASC → 10, 11, 12
		$db = $this->db(['id' => 5], [['id' => 10, 'pos' => 0], ['id' => 11, 'pos' => 0], ['id' => 12, 'pos' => 0]], $log);

		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 5, $this->req(['id' => 10, 'direction' => 'down']), $this->tables(), $db, $this->registry(), null, $this->admin());

		$this->assertSame(200, $this->httpStatus($res));
		$this->assertSame([11, 10, 12], $res->getPayload()['data']['order']);
		$this->assertSame(['begin', 'select', 'execute', 'execute', 'execute', 'commit'], $this->kinds($log));
		$this->assertSame([[1, 11], [2, 10], [3, 12]], $this->updates($log));
		$this->assertStringContainsString('ORDER BY `order_pos` ASC, `id` ASC FOR UPDATE', $log[1][1][0]);
	}

	public function testUpOnFirstRowIsNoOpButRenumbersOnlyChangedRows(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], [['id' => 10, 'pos' => 1], ['id' => 11, 'pos' => 0], ['id' => 12, 'pos' => 7]], $log);

		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 5, $this->req(['id' => 10, 'direction' => 'up']), $this->tables(), $db, $this->registry(), null, $this->admin());

		$this->assertSame(200, $this->httpStatus($res));
		$this->assertSame([10, 11, 12], $res->getPayload()['data']['order']);
		// 10 už má 1 → bez UPDATE; 11 → 2, 12 → 3
		$this->assertSame([[2, 11], [3, 12]], $this->updates($log));
		$this->assertSame('commit', end($log)[0]);
	}

	public function testUpInTheMiddleSwapsWithPredecessor(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], [['id' => 10, 'pos' => 1], ['id' => 11, 'pos' => 2], ['id' => 12, 'pos' => 3]], $log);

		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 5, $this->req(['id' => 12, 'direction' => 'up']), $this->tables(), $db, $this->registry(), null, $this->admin());

		$this->assertSame([10, 12, 11], $res->getPayload()['data']['order']);
		$this->assertSame([[2, 12], [3, 11]], $this->updates($log));
	}

	// ── Errors ───────────────────────────────────────────────────────────────

	public function testForeignRowIs404AndRollsBack(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], [['id' => 10, 'pos' => 1], ['id' => 11, 'pos' => 2]], $log);

		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 5, $this->req(['id' => 99, 'direction' => 'down']), $this->tables(), $db, $this->registry(), null, $this->admin());

		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('RECORD_NOT_FOUND', $res->getPayload()['error']['code']);
		$this->assertSame(['begin', 'select', 'rollback'], $this->kinds($log));
	}

	public function testReadOnlyParentIs422WithoutTouchingDb(): void
	{
		$log = [];
		$db = $this->db(['id' => 5, 'docState' => 40], [['id' => 10, 'pos' => 1]], $log);
		$config = ConfigRuntimeFactory::fromItems([
			'test.states' => ['10' => ['stateStyle' => 'concept'], '40' => ['stateStyle' => 'done', 'readOnly' => 1]],
		]);

		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 5, $this->req(['id' => 10, 'direction' => 'down']), $this->tables(parentDocStates: true), $db, $this->registry(), $config, $this->admin());

		$this->assertSame(422, $this->httpStatus($res));
		$this->assertSame('DOCUMENT_READONLY', $res->getPayload()['error']['code']);
		$this->assertSame('Document is read-only in state 40.', $res->getPayload()['error']['message']);
		$this->assertSame([], $log);
	}

	public function testEditableParentWithDocStatesPasses(): void
	{
		$log = [];
		$db = $this->db(['id' => 5, 'docState' => 10], [['id' => 10, 'pos' => 1], ['id' => 11, 'pos' => 2]], $log);
		$config = ConfigRuntimeFactory::fromItems([
			'test.states' => ['10' => ['stateStyle' => 'concept'], '40' => ['stateStyle' => 'done', 'readOnly' => 1]],
		]);

		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 5, $this->req(['id' => 10, 'direction' => 'down']), $this->tables(parentDocStates: true), $db, $this->registry(), $config, $this->admin());

		$this->assertSame(200, $this->httpStatus($res));
		$this->assertSame([11, 10], $res->getPayload()['data']['order']);
	}

	public function testTabWithoutOrderColumnIs400(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], [], $log);
		$res = $this->ctrl->subtableMove('parent_tbl', 'items', 5, $this->req(['id' => 10, 'direction' => 'down']), $this->tables(), $db, $this->registry(), null, $this->admin());
		$this->assertSame(400, $this->httpStatus($res));
		$this->assertSame('SUBTABLE_NOT_ORDERED', $res->getPayload()['error']['code']);
		$this->assertSame([], $log);
	}

	public function testInvalidBodyIs400(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], [], $log);
		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 5, $this->req(['id' => 10, 'direction' => 'sideways']), $this->tables(), $db, $this->registry(), null, $this->admin());
		$this->assertSame(400, $this->httpStatus($res));
		$this->assertSame('BAD_REQUEST', $res->getPayload()['error']['code']);
		$this->assertSame([], $log);
	}

	public function testMissingParentIs404(): void
	{
		$log = [];
		$db = $this->db(null, [], $log);
		$res = $this->ctrl->subtableMove('parent_tbl', 'ordered', 99, $this->req(['id' => 10, 'direction' => 'down']), $this->tables(), $db, $this->registry(), null, $this->admin());
		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('RECORD_NOT_FOUND', $res->getPayload()['error']['code']);
	}
}
