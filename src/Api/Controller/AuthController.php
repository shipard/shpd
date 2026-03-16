<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;

class AuthController
{
	private const int SESSION_TTL_SECONDS = 86400; // 24 hours

	public function login(Request $request, DataSourceConnection $db): Response
	{
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

		if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
			return Response::error('UNAUTHORIZED', 'Invalid credentials', 401);
		}

		if (!$user['is_active']) {
			return Response::error('UNAUTHORIZED', 'Account is inactive', 401);
		}

		$userId = (int) $user['id'];
		[$token, $expiresAt] = $this->createSession($userId, $db);

		return Response::success([
			'token'      => $token,
			'expires_at' => $expiresAt,
			'user'       => [
				'id'        => $userId,
				'login'     => $user['login'],
				'full_name' => $user['full_name'],
			],
		]);
	}

	public function refresh(Request $request, AuthContext $auth, DataSourceConnection $db): Response
	{
		if (!$auth->isAuthenticated || $auth->tokenType !== 'session' || $auth->token === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		$this->invalidateSession($auth->token, $db);

		[$token, $expiresAt] = $this->createSession((int) $auth->userId, $db);

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
	protected function createSession(int $userId, DataSourceConnection $db): array
	{
		$token = 'shpd_st_' . $this->generateToken(48);
		$expiresAt = date('c', time() + self::SESSION_TTL_SECONDS);

		$db->insertRow('core_system_sessions', [
			'user_id' => $userId,
			'token'   => $token,
			'created' => date('Y-m-d H:i:s'),
			'expires' => date('Y-m-d H:i:s', time() + self::SESSION_TTL_SECONDS),
		]);

		return [$token, $expiresAt];
	}

	protected function invalidateSession(string $token, DataSourceConnection $db): void
	{
		$db->execute(
			'DELETE FROM core_system_sessions WHERE token = %s',
			$token,
		);
	}

	protected function generateToken(int $length): string
	{
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$charCount = strlen($chars);
		$result = '';
		for ($i = 0; $i < $length; $i++) {
			$result .= $chars[random_int(0, $charCount - 1)];
		}
		return $result;
	}
}
