<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;

class ViewerControllerGuardTest extends TestCase
{
	private DataSourceConnection $db;
	private ViewerRegistry $registry;
	private ViewerController $ctrl;

	protected function setUp(): void
	{
		$ref = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();

		$this->registry = new ViewerRegistry();
		$this->registry->register(new ViewerDefinition(
			id: 'core.system.users',
			name: 'Users',
			table: 'core_system_users',
			class: null,
			moduleId: 'core.system',
			icon: null,
		));
		$this->registry->register(new ViewerDefinition(
			id: 'hosting.core.servers',
			name: 'Servers',
			table: 'hosting_core_servers',
			class: null,
			moduleId: 'hosting.core',
			icon: null,
		));

		$this->ctrl = new ViewerController();
	}

	/** @return array<string, TableDefinition> */
	private function tables(): array
	{
		return [
			'hosting_core_servers' => TableDefinition::fromArray([
				'tableId'   => 10,
				'name'      => 'hosting_core_servers',
				'adminOnly' => true,
				'columns'   => [
					['id' => 'id',   'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
				],
			]),
		];
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	private function req(): Request
	{
		return Request::fromArray('GET', '/_ui/viewer/core.system.users/rows', [], '', []);
	}

	private function nonAdmin(): AuthContext
	{
		return new AuthContext(true, 2, 'session', 'shpd_st_y');
	}

	private function admin(): AuthContext
	{
		return new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true);
	}

	public function testNonAdminGets403OnSystemTableViewer(): void
	{
		$responses = [
			'meta'   => $this->ctrl->meta('core.system.users', $this->nonAdmin(), $this->registry, [], $this->db),
			'rows'   => $this->ctrl->rows('core.system.users', $this->req(), $this->nonAdmin(), $this->registry, [], $this->db),
			'detail' => $this->ctrl->detail('core.system.users', 1, $this->nonAdmin(), $this->registry, [], $this->db),
		];

		foreach ($responses as $action => $resp) {
			$this->assertSame(403, $this->getStatus($resp), "action {$action}");
			$this->assertSame('FORBIDDEN_SYSTEM_TABLE', $resp->getPayload()['error']['code'], "action {$action}");
		}
	}

	public function testNonAdminGets403OnAdminOnlyViewer(): void
	{
		$responses = [
			'meta'   => $this->ctrl->meta('hosting.core.servers', $this->nonAdmin(), $this->registry, $this->tables(), $this->db),
			'rows'   => $this->ctrl->rows('hosting.core.servers', $this->req(), $this->nonAdmin(), $this->registry, $this->tables(), $this->db),
			'detail' => $this->ctrl->detail('hosting.core.servers', 1, $this->nonAdmin(), $this->registry, $this->tables(), $this->db),
		];

		foreach ($responses as $action => $resp) {
			$this->assertSame(403, $this->getStatus($resp), "action {$action}");
			$this->assertSame('FORBIDDEN_ADMIN_ONLY', $resp->getPayload()['error']['code'], "action {$action}");
		}
	}

	public function testAdminPassesGuard(): void
	{
		// Viewer nemá class → 500 VIEWER_CLASS_NOT_FOUND. Podstatné je,
		// že admin neskončil na 403 — guard ho pustil dál.
		$resp = $this->ctrl->meta('core.system.users', $this->admin(), $this->registry, [], $this->db);

		$this->assertSame(500, $this->getStatus($resp));
		$this->assertSame('VIEWER_CLASS_NOT_FOUND', $resp->getPayload()['error']['code']);
	}

	public function testAdminPassesAdminOnlyViewer(): void
	{
		$resp = $this->ctrl->meta('hosting.core.servers', $this->admin(), $this->registry, $this->tables(), $this->db);

		$this->assertSame(500, $this->getStatus($resp));
		$this->assertSame('VIEWER_CLASS_NOT_FOUND', $resp->getPayload()['error']['code']);
	}

	public function testNonAdminPassesAdminOnlyViewerWithoutTableDef(): void
	{
		// Viewer bez odpovídající TableDefinition → guard jen s prefixem.
		$resp = $this->ctrl->meta('hosting.core.servers', $this->nonAdmin(), $this->registry, [], $this->db);

		$this->assertSame(500, $this->getStatus($resp));
		$this->assertSame('VIEWER_CLASS_NOT_FOUND', $resp->getPayload()['error']['code']);
	}

	public function testUnknownViewerStill404(): void
	{
		$resp = $this->ctrl->meta('nonexistent', $this->nonAdmin(), $this->registry, [], $this->db);
		$this->assertSame(404, $this->getStatus($resp));
	}
}
