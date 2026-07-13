<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\SessionService;
use Shipard\Core\Auth\AuthPolicy;
use Shipard\Core\Database\DataSourceConnection;

class AuthController
{
	public function __construct(
		private readonly SessionService $sessions = new SessionService(),
	) {}

	public function login(Request $request, DataSourceConnection $db, ?AuthPolicy $policy = null): Response
	{
		if ($policy !== null && !$policy->localLogin) {
			return Response::error('AUTH_METHOD_DISABLED', 'Local login is disabled for this data source', 403);
		}

		$body = $request->getBody();
		if ($body === null) {
			return Response::error('BAD_REQUEST', 'Request body is required', 400);
		}

		$login = isset($body['login']) ? (string) $body['login'] : null;
		$password = isset($body['password']) ? (string) $body['password'] : null;

		if ($login === null || $login === '') {
			return Response::error('VALIDATION_ERROR', 'Field login is required', 422);
		}
		if ($password === null || $password === '') {
			return Response::error('VALIDATION_ERROR', 'Field password is required', 422);
		}

		$user = $this->findUserByLogin($login, $db);

		// NULL password_hash = OIDC/JIT uživatel bez lokálního hesla — stejná
		// odpověď jako špatné heslo, ať se existence účtu nedá odvodit.
		if ($user === null || $user['password_hash'] === null
			|| !password_verify($password, (string) $user['password_hash'])) {
			return Response::error('UNAUTHORIZED', 'Invalid credentials', 401);
		}

		if (!$user['is_active']) {
			return Response::error('UNAUTHORIZED', 'Account is inactive', 401);
		}

		$userId = (int) $user['id'];
		[$token, $expiresAt] = $this->createSession($userId, $db, $request->getClientIp());

		return Response::success([
			'token'      => $token,
			'expires_at' => $expiresAt,
			'user'       => [
				'id'           => $userId,
				'login'        => $user['login'],
				'full_name'    => $user['full_name'],
				'is_admin'     => (bool) ($user['is_admin'] ?? false),
				// Panel změny hesla se OIDC/JIT účtům bez hesla nezobrazuje.
				'has_password' => $user['password_hash'] !== null,
			],
		]);
	}

	public function refresh(Request $request, AuthContext $auth, DataSourceConnection $db): Response
	{
		if (!$auth->isAuthenticated || $auth->tokenType !== 'session' || $auth->token === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		$this->invalidateSession($auth->token, $db);

		[$token, $expiresAt] = $this->createSession((int) $auth->userId, $db, $request->getClientIp());

		return Response::success([
			'token'      => $token,
			'expires_at' => $expiresAt,
		]);
	}

	public function logout(Request $request, AuthContext $auth, DataSourceConnection $db): Response
	{
		if (!$auth->isAuthenticated || $auth->tokenType !== 'session' || $auth->token === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		$this->invalidateSession($auth->token, $db);

		return Response::success(null, 204);
	}

	protected function findUserByLogin(string $login, DataSourceConnection $db): ?array
	{
		return $db->fetchRow(
			'SELECT * FROM core_system_users WHERE login = %s',
			$login,
		);
	}

	/** @return array{0: string, 1: string} [token, expires_at ISO-8601] */
	protected function createSession(int $userId, DataSourceConnection $db, ?string $clientIp = null): array
	{
		return $this->sessions->createSession($userId, $db, $clientIp);
	}

	protected function invalidateSession(string $token, DataSourceConnection $db): void
	{
		$this->sessions->invalidateSession($token, $db);
	}
}
