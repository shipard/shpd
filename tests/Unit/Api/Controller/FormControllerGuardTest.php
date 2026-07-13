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
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Module\ModulePathResolver;

class FormControllerGuardTest extends TestCase
{
	private DataSourceConnection $db;
	private FormController $ctrl;
	private FormRegistry $formRegistry;
	private LookupRegistry $lookupRegistry;
	private ModulePathResolver $resolver;

	protected function setUp(): void
	{
		$ref = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();

		$this->ctrl           = new FormController();
		$this->formRegistry   = new FormRegistry();
		$this->lookupRegistry = new LookupRegistry();
		$this->resolver       = new ModulePathResolver([]);
	}

	private function tables(): array
	{
		return [
			'core_system_users' => TableDefinition::fromArray([
				'tableId' => 1,
				'name'    => 'Users',
				'columns' => [
					['id' => 'id',    'name' => 'ID',    'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 100],
				],
			]),
			'secrets' => TableDefinition::fromArray([
				'tableId' => 9,
				'name'    => 'secrets',
				'columns' => [
					['id' => 'id',       'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'name',     'name' => 'Name', 'type' => 'varchar', 'length' => 100],
					['id' => 'key_hash', 'name' => 'Hash', 'type' => 'varchar', 'length' => 64, 'sensitive' => true],
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

	private function req(string $method = 'GET', string $body = ''): Request
	{
		return Request::fromArray($method, '/_ui/form/x', [], $body, []);
	}

	private function nonAdmin(): AuthContext
	{
		return new AuthContext(true, 2, 'session', 'shpd_st_y');
	}

	public function testMetaOnSystemTableRequiresAdmin(): void
	{
		$resp = $this->ctrl->meta(
			'core_system_users', null, $this->tables(), $this->db,
			$this->formRegistry, null, $this->lookupRegistry, $this->resolver,
			'en', [], $this->nonAdmin(),
		);

		$this->assertSame(403, $this->getStatus($resp));
		$this->assertSame('FORBIDDEN_SYSTEM_TABLE', $resp->getPayload()['error']['code']);
	}

	public function testMetaOnSystemTableBlockedWithoutAuthContext(): void
	{
		// Chybějící AuthContext (starý wiring, testy) = ne-admin — fail closed.
		$resp = $this->ctrl->meta(
			'core_system_users', null, $this->tables(), $this->db,
			$this->formRegistry, null, $this->lookupRegistry, $this->resolver,
		);

		$this->assertSame(403, $this->getStatus($resp));
	}

	public function testSaveOnSystemTableRequiresAdmin(): void
	{
		$resp = $this->ctrl->save(
			'core_system_users', null, $this->req('POST', '{"login":"x"}'), $this->tables(), $this->db,
			null, $this->formRegistry, $this->resolver, $this->lookupRegistry, 'en',
			null, null, $this->nonAdmin(),
		);

		$this->assertSame(403, $this->getStatus($resp));
		$this->assertSame('FORBIDDEN_SYSTEM_TABLE', $resp->getPayload()['error']['code']);
	}

	public function testRecalculateOnSystemTableRequiresAdmin(): void
	{
		$resp = $this->ctrl->recalculate(
			'core_system_users', $this->req('POST', '{"changedColumn":"login","data":{}}'), $this->tables(), $this->db,
			$this->formRegistry, null, $this->lookupRegistry, $this->resolver, 'en', $this->nonAdmin(),
		);

		$this->assertSame(403, $this->getStatus($resp));
	}

	public function testSaveWithSensitiveColumnReturns400(): void
	{
		$resp = $this->ctrl->save(
			'secrets', null, $this->req('POST', '{"name":"A","key_hash":"evil"}'), $this->tables(), $this->db,
			null, $this->formRegistry, $this->resolver, $this->lookupRegistry, 'en',
			null, null, $this->nonAdmin(),
		);

		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}
}
