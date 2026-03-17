<?php
declare(strict_types=1);

namespace Shipard\Api\Middleware;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Core\Database\DataSourceConnection;

class AuthMiddleware
{
	public function handle(
		Request $request,
		Route $route,
		DataSourceConnection $db,
		bool $openApiPublic = false,
	): AuthContext|Response {
		// Endpoints that never require a token
		if ($this->isExempt($route, $openApiPublic)) {
			return AuthContext::anonymous();
		}

		$header = $request->getHeader('Authorization');

		if ($header === null) {
			return AuthContext::anonymous();
		}

		if (!str_starts_with($header, 'Bearer ')) {
			return Response::error('UNAUTHORIZED', 'Invalid authorization header', 401);
		}

		$token = trim(substr($header, 7));

		if ($token === '') {
			return AuthContext::anonymous();
		}

		if (str_starts_with($token, 'shpd_ak_')) {
			return $this->handleApiKey($token, $request->getClientIp(), $db);
		}

		if (str_starts_with($token, 'shpd_st_')) {
			return $this->handleSession($token, $db);
		}

		return Response::error('UNAUTHORIZED', 'Unknown token type', 401);
	}

	private function isExempt(Route $route, bool $openApiPublic): bool
	{
		if ($route->controller === 'auth' && $route->action === 'login') {
			return true;
		}
		if ($openApiPublic && $route->controller === 'openapi' && $route->action === 'spec') {
			return true;
		}
		return false;
	}

	private function handleApiKey(string $token, ?string $clientIp, DataSourceConnection $db): AuthContext|Response
	{
		$keyPart = substr($token, strlen('shpd_ak_'));
		$keyPrefix = substr($keyPart, 0, 12);
		$keyHash = hash('sha256', $token);

		$row = $this->lookupApiKey($keyHash, $keyPrefix, $db);

		if ($row === null) {
			return Response::error('UNAUTHORIZED', 'Invalid API key', 401);
		}

		if (!$row['is_active']) {
			return Response::error('UNAUTHORIZED', 'API key is inactive', 401);
		}

		if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
			return Response::error('UNAUTHORIZED', 'API key has expired', 401);
		}

		if ($row['allowed_ips'] !== null) {
			$allowedIps = json_decode((string) $row['allowed_ips'], true);
			if (is_array($allowedIps) && $clientIp !== null && !in_array($clientIp, $allowedIps, true)) {
				return Response::error('UNAUTHORIZED', 'IP address not allowed', 401);
			}
		}

		$this->updateApiKeyLastUsed((int) $row['id'], $db);

		return new AuthContext(true, (int) $row['user_id'], 'api_key', $token);
	}

	private function handleSession(string $token, DataSourceConnection $db): AuthContext|Response
	{
		$row = $this->lookupSession($token, $db);

		if ($row === null) {
			return Response::error('UNAUTHORIZED', 'Invalid session token', 401);
		}

		if (strtotime((string) $row['expires']) < time()) {
			return Response::error('UNAUTHORIZED', 'Session token has expired', 401);
		}

		return new AuthContext(true, (int) $row['user_id'], 'session', $token);
	}

	protected function lookupApiKey(string $keyHash, string $keyPrefix, DataSourceConnection $db): ?array
	{
		return $db->fetchRow(
			'SELECT * FROM core_system_api_keys WHERE key_prefix = %s AND key_hash = %s',
			$keyPrefix,
			$keyHash,
		);
	}

	protected function lookupSession(string $token, DataSourceConnection $db): ?array
	{
		return $db->fetchRow(
			'SELECT * FROM core_system_sessions WHERE token = %s',
			$token,
		);
	}

	protected function updateApiKeyLastUsed(int $id, DataSourceConnection $db): void
	{
		$db->execute(
			'UPDATE core_system_api_keys SET last_used_at = NOW() WHERE id = %i',
			$id,
		);
	}
}
