<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AuthController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;

class TestableAuthController extends AuthController
{
	private ?array $mockUser = null;
	/** @var array<string, array> token => session row */
	private array $sessions = [];
	private string $nextToken = 'shpd_st_testtoken000000000000000000000000000000000000000000';
	public ?string $lastDeletedToken = null;
	public ?array $lastInsertedSession = null;

	public function setMockUser(?array $user): void
	{
		$this->mockUser = $user;
	}

	public function addMockSession(string $token, array $row): void
	{
		$this->sessions[$token] = $row;
	}

	public function setNextToken(string $token): void
	{
		$this->nextToken = $token;
	}

	protected function findUserByLogin(string $login, DataSourceConnection $db): ?array
	{
		return $this->mockUser;
	}

	protected function createSession(int $userId, DataSourceConnection $db): array
	{
		$token = $this->nextToken;
		$expiresAt = date('c', time() + 86400);
		$this->sessions[$token] = [
			'user_id' => $userId,
			'token'   => $token,
			'expires' => date('Y-m-d H:i:s', time() + 86400),
		];
		$this->lastInsertedSession = $this->sessions[$token];
		return [$token, $expiresAt];
	}

	protected function invalidateSession(string $token, DataSourceConnection $db): void
	{
		$this->lastDeletedToken = $token;
		unset($this->sessions[$token]);
	}
}

class AuthControllerTest extends TestCase
{
	private DataSourceConnection $db;
	private TestableAuthController $controller;

	protected function setUp(): void
	{
		$ref = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();
		$this->controller = new TestableAuthController();
	}

	private function req(array $body = []): Request
	{
		return Request::fromArray('POST', '/api/v1/_auth/login', [], json_encode($body), []);
	}

	private function getStatus(Response $response): int
	{
		$ref = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	// --- Login ---

	public function testLoginWithCorrectCredentialsReturnsToken(): void
	{
		$hash = password_hash('secret123', PASSWORD_DEFAULT);
		$this->controller->setMockUser([
			'id'            => 1,
			'login'         => 'jan.novak',
			'full_name'     => 'Jan Novák',
			'password_hash' => $hash,
			'is_active'     => 1,
		]);

		$response = $this->controller->login($this->req(['login' => 'jan.novak', 'password' => 'secret123']), $this->db);
		$payload = $response->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertStringStartsWith('shpd_st_', $payload['data']['token']);
		$this->assertArrayHasKey('expires_at', $payload['data']);
		$this->assertSame('jan.novak', $payload['data']['user']['login']);
	}

	public function testLoginWithWrongPasswordReturns401(): void
	{
		$hash = password_hash('secret123', PASSWORD_DEFAULT);
		$this->controller->setMockUser([
			'id'            => 1,
			'login'         => 'jan.novak',
			'full_name'     => 'Jan Novák',
			'password_hash' => $hash,
			'is_active'     => 1,
		]);

		$response = $this->controller->login($this->req(['login' => 'jan.novak', 'password' => 'wrong']), $this->db);

		$this->assertFalse($response->getPayload()['success']);
		$this->assertSame(401, $this->getStatus($response));
	}

	public function testLoginWithUnknownLoginReturns401(): void
	{
		$this->controller->setMockUser(null);

		$response = $this->controller->login($this->req(['login' => 'nobody', 'password' => 'pass']), $this->db);

		$this->assertSame(401, $this->getStatus($response));
	}

	public function testLoginWithInactiveUserReturns401(): void
	{
		$hash = password_hash('pass', PASSWORD_DEFAULT);
		$this->controller->setMockUser([
			'id'            => 2,
			'login'         => 'inactive',
			'full_name'     => 'Inactive User',
			'password_hash' => $hash,
			'is_active'     => 0,
		]);

		$response = $this->controller->login($this->req(['login' => 'inactive', 'password' => 'pass']), $this->db);

		$this->assertSame(401, $this->getStatus($response));
	}

	public function testLoginWithMissingFieldsReturnsError(): void
	{
		$response = $this->controller->login($this->req(['login' => 'jan']), $this->db);
		$this->assertFalse($response->getPayload()['success']);

		$response2 = $this->controller->login($this->req(['password' => 'pass']), $this->db);
		$this->assertFalse($response2->getPayload()['success']);
	}

	public function testLoginWithNullBodyReturnsError(): void
	{
		$req = Request::fromArray('POST', '/api/v1/_auth/login', [], 'not json', []);
		$response = $this->controller->login($req, $this->db);
		$this->assertFalse($response->getPayload()['success']);
	}

	// --- Refresh ---

	public function testRefreshCreatesNewTokenAndInvalidatesOld(): void
	{
		$oldToken = 'shpd_st_oldtoken000000000000000000000000000000000000000000';
		$newToken = 'shpd_st_newtoken000000000000000000000000000000000000000000';
		$this->controller->setNextToken($newToken);

		$auth = new AuthContext(true, 5, 'session', $oldToken);
		$req = Request::fromArray('POST', '/api/v1/_auth/refresh', [], '', [
			'HTTP_AUTHORIZATION' => "Bearer {$oldToken}",
		]);

		$response = $this->controller->refresh($req, $auth, $this->db);
		$payload = $response->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertSame($newToken, $payload['data']['token']);
		$this->assertSame($oldToken, $this->controller->lastDeletedToken);
	}

	public function testRefreshWithApiKeyTokenReturns401(): void
	{
		$auth = new AuthContext(true, 5, 'api_key', 'shpd_ak_somekey');
		$req = Request::fromArray('POST', '/api/v1/_auth/refresh', [], '', []);

		$response = $this->controller->refresh($req, $auth, $this->db);

		$this->assertSame(401, $this->getStatus($response));
	}

	public function testRefreshWithUnauthenticatedContextReturns401(): void
	{
		$auth = AuthContext::anonymous();
		$req = Request::fromArray('POST', '/api/v1/_auth/refresh', [], '', []);

		$response = $this->controller->refresh($req, $auth, $this->db);

		$this->assertSame(401, $this->getStatus($response));
	}

	// --- Logout ---

	public function testLogoutDeletesSessionAndReturns204(): void
	{
		$token = 'shpd_st_mytoken0000000000000000000000000000000000000000000';
		$auth = new AuthContext(true, 7, 'session', $token);
		$req = Request::fromArray('DELETE', '/api/v1/_auth/logout', [], '', []);

		$response = $this->controller->logout($req, $auth, $this->db);
		$payload = $response->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertSame(204, $this->getStatus($response));
		$this->assertSame($token, $this->controller->lastDeletedToken);
	}

	public function testLogoutWithoutSessionTokenReturns401(): void
	{
		$auth = AuthContext::anonymous();
		$req = Request::fromArray('DELETE', '/api/v1/_auth/logout', [], '', []);

		$response = $this->controller->logout($req, $auth, $this->db);

		$this->assertSame(401, $this->getStatus($response));
	}
}
