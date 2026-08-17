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

	private function encryptedDef(): TableDefinition
	{
		// api_key záměrně BEZ 'sensitive' — sensitivitu musí odvodit typ encrypted_text.
		return TableDefinition::fromArray([
			'tableId' => 11,
			'name'    => 'ai_backends',
			'columns' => [
				['id' => 'id',      'name' => 'ID',      'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'name',    'name' => 'Name',    'type' => 'varchar', 'length' => 100],
				['id' => 'api_key', 'name' => 'API key', 'type' => 'encrypted_text', 'nullable' => true],
			],
		]);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	private function adminOnlyDef(): TableDefinition
	{
		return TableDefinition::fromArray([
			'tableId'   => 10,
			'name'      => 'hosting_core_servers',
			'adminOnly' => true,
			'columns'   => [
				['id' => 'id',   'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
			],
		]);
	}

	public function testNonAdminBlockedOnSystemTable(): void
	{
		$resp = TableAccessGuard::guardTable('core_system_users', new AuthContext(true, 1, 'session', 't'));

		$this->assertInstanceOf(Response::class, $resp);
		$this->assertSame(403, $this->getStatus($resp));
		$this->assertSame('FORBIDDEN_SYSTEM_TABLE', $resp->getPayload()['error']['code']);
	}

	public function testAdminPassesSystemTable(): void
	{
		$auth = new AuthContext(true, 1, 'session', 't', isAdmin: true);
		$this->assertNull(TableAccessGuard::guardTable('core_system_users', $auth));
	}

	public function testNonAdminPassesRegularTable(): void
	{
		$this->assertNull(TableAccessGuard::guardTable('base_persons', new AuthContext(false)));
	}

	public function testNonAdminBlockedOnAdminOnlyTable(): void
	{
		$resp = TableAccessGuard::guardTable(
			'hosting_core_servers', new AuthContext(true, 2, 'session', 't'), $this->adminOnlyDef(),
		);

		$this->assertInstanceOf(Response::class, $resp);
		$this->assertSame(403, $this->getStatus($resp));
		$this->assertSame('FORBIDDEN_ADMIN_ONLY', $resp->getPayload()['error']['code']);
	}

	public function testAdminPassesAdminOnlyTable(): void
	{
		$auth = new AuthContext(true, 1, 'session', 't', isAdmin: true);
		$this->assertNull(TableAccessGuard::guardTable('hosting_core_servers', $auth, $this->adminOnlyDef()));
	}

	public function testNonAdminPassesAdminOnlyTableWithoutDef(): void
	{
		// Bez TableDefinition guard vynucuje jen prefix — flagovaná tabulka projde.
		$auth = new AuthContext(true, 2, 'session', 't');
		$this->assertNull(TableAccessGuard::guardTable('hosting_core_servers', $auth, null));
	}

	public function testNonAdminPassesRegularTableWithDef(): void
	{
		$auth = new AuthContext(true, 2, 'session', 't');
		$this->assertNull(TableAccessGuard::guardTable('secrets', $auth, $this->secretsDef()));
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

	public function testWhitelistedSensitiveColumnPasses(): void
	{
		$this->assertNull(TableAccessGuard::rejectSensitiveInput(
			['name' => 'A', 'key_hash' => 'x'],
			$this->secretsDef(),
			['key_hash'],
		));
	}

	public function testWhitelistOfOtherColumnDoesNotHelp(): void
	{
		$resp = TableAccessGuard::rejectSensitiveInput(
			['name' => 'A', 'key_hash' => 'x'],
			$this->secretsDef(),
			['some_other_column'],
		);

		$this->assertInstanceOf(Response::class, $resp);
		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}

	public function testStripSensitiveRemovesEncryptedTextWithoutFlag(): void
	{
		$row = ['id' => 1, 'name' => 'A', 'api_key' => 'ciphertext'];
		$stripped = TableAccessGuard::stripSensitive($row, $this->encryptedDef());

		$this->assertSame(['id' => 1, 'name' => 'A'], $stripped);
	}

	public function testRejectSensitiveInputCatchesEncryptedTextWithoutFlag(): void
	{
		$resp = TableAccessGuard::rejectSensitiveInput(['name' => 'A', 'api_key' => 'ciphertext'], $this->encryptedDef());

		$this->assertInstanceOf(Response::class, $resp);
		$this->assertSame(400, $this->getStatus($resp));
		$this->assertSame('SENSITIVE_COLUMN', $resp->getPayload()['error']['code']);
	}
}
