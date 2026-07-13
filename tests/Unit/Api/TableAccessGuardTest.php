<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Database\TableDefinition;

class TableAccessGuardTest extends TestCase
{
	private function secretsDef(): TableDefinition
	{
		return TableDefinition::fromArray([
			'tableId' => 9,
			'name'    => 'secrets',
			'columns' => [
				['id' => 'id',       'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'name',     'name' => 'Name', 'type' => 'varchar', 'length' => 100],
				['id' => 'key_hash', 'name' => 'Hash', 'type' => 'varchar', 'length' => 64, 'sensitive' => true],
			],
		]);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	public function testNonAdminBlockedOnSystemTable(): void
	{
		$resp = TableAccessGuard::guardSystemTable('core_system_users', new AuthContext(true, 1, 'session', 't'));

		$this->assertInstanceOf(Response::class, $resp);
		$this->assertSame(403, $this->getStatus($resp));
		$this->assertSame('FORBIDDEN_SYSTEM_TABLE', $resp->getPayload()['error']['code']);
	}

	public function testAdminPassesSystemTable(): void
	{
		$auth = new AuthContext(true, 1, 'session', 't', isAdmin: true);
		$this->assertNull(TableAccessGuard::guardSystemTable('core_system_users', $auth));
	}

	public function testNonAdminPassesRegularTable(): void
	{
		$this->assertNull(TableAccessGuard::guardSystemTable('base_persons', new AuthContext(false)));
	}

	public function testStripSensitiveRemovesFlaggedColumns(): void
	{
		$row = ['id' => 1, 'name' => 'A', 'key_hash' => 'abc'];
		$stripped = TableAccessGuard::stripSensitive($row, $this->secretsDef());

		$this->assertSame(['id' => 1, 'name' => 'A'], $stripped);
	}

	public function testRejectSensitiveInputReturns400(): void
	{
		$resp = TableAccessGuard::rejectSensitiveInput(['name' => 'A', 'key_hash' => 'x'], $this->secretsDef());

		$this->assertInstanceOf(Response::class, $resp);
		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}

	public function testRejectSensitiveInputPassesCleanBody(): void
	{
		$this->assertNull(TableAccessGuard::rejectSensitiveInput(['name' => 'A'], $this->secretsDef()));
	}
}
