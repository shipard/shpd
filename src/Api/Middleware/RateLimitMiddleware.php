<?php
declare(strict_types=1);

namespace Shipard\Api\Middleware;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Core\Database\DataSourceConnection;

class RateLimitMiddleware
{
	private const int WINDOW_SECONDS = 60;

	/** Limits: requests per window per token type */
	private const array LIMITS = [
		'api_key' => 1000,
		'session' => 300,
		'login'   => 10,   // per IP, for login endpoint
		'default' => 60,   // unauthenticated non-login requests
	];

	/** Rate limit headers populated after handle(). */
	private array $headers = [];

	/**
	 * Check the rate limit for this request.
	 * Returns null if within limits (pipeline continues), or a 429 Response.
	 */
	public function handle(
		Request $request,
		AuthContext $auth,
		Route $route,
		DataSourceConnection $db,
	): ?Response {
		$this->headers = [];

		[$key, $type, $limit] = $this->resolveKey($request, $auth, $route);

		$windowStart = $this->currentWindowStart();
		$count       = $this->getCount($key, $windowStart, $db);

		if ($count >= $limit) {
			$resetAt    = $windowStart + self::WINDOW_SECONDS;
			$retryAfter = max(0, $resetAt - time());

			$this->headers = [
				'X-RateLimit-Limit'     => (string) $limit,
				'X-RateLimit-Remaining' => '0',
				'X-RateLimit-Reset'     => (string) $resetAt,
			];

			$resp = Response::error(
				'RATE_LIMITED',
				"Too many requests. Retry after {$retryAfter} seconds.",
				429,
				[['field' => '_retry_after', 'code' => 'SECONDS', 'message' => (string) $retryAfter]],
			);

			foreach ($this->headers as $name => $value) {
				$resp = $resp->withHeader($name, $value);
			}

			return $resp;
		}

		$this->incrementCount($key, $type, $windowStart, $count, $db);

		$resetAt   = $windowStart + self::WINDOW_SECONDS;
		$remaining = max(0, $limit - $count - 1);

		$this->headers = [
			'X-RateLimit-Limit'     => (string) $limit,
			'X-RateLimit-Remaining' => (string) $remaining,
			'X-RateLimit-Reset'     => (string) $resetAt,
		];

		return null;
	}

	public function getHeaders(): array
	{
		return $this->headers;
	}

	// -------------------------------------------------------------------------
	// Protected DB methods (overridable for testing)
	// -------------------------------------------------------------------------

	protected function getCount(string $key, int $windowStart, DataSourceConnection $db): int
	{
		$row = $db->fetchRow(
			'SELECT count FROM core_system_rate_limits WHERE rate_key = %s AND window_start = %i',
			$key,
			$windowStart,
		);
		return $row !== null ? (int) $row['count'] : 0;
	}

	protected function incrementCount(string $key, string $type, int $windowStart, int $current, DataSourceConnection $db): void
	{
		if ($current === 0) {
			$db->insertRow('core_system_rate_limits', [
				'rate_key'     => $key,
				'rate_type'    => $type,
				'window_start' => $windowStart,
				'count'        => 1,
			]);
		} else {
			$db->execute(
				'UPDATE core_system_rate_limits SET count = count + 1 WHERE rate_key = %s AND window_start = %i',
				$key,
				$windowStart,
			);
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/** @return array{0:string, 1:string, 2:int} [key, type, limit] */
	private function resolveKey(Request $request, AuthContext $auth, Route $route): array
	{
		// Login-class endpointy (lokální login, OIDC start/exchange): limit
		// per IP. oidcCallback zůstává v defaultu — chrání ho single-use state.
		if ($route->controller === 'auth'
			&& in_array($route->action, ['login', 'oidcStart', 'oidcExchange'], true)) {
			$ip   = $request->getClientIp() ?? 'unknown';
			$key  = hash('sha256', 'login:' . $ip);
			return [$key, 'login', self::LIMITS['login']];
		}

		if ($auth->isAuthenticated && $auth->token !== null) {
			$key   = hash('sha256', $auth->token);
			$type  = $auth->tokenType === 'api_key' ? 'api_key' : 'session';
			return [$key, $type, self::LIMITS[$type]];
		}

		// Unauthenticated: rate limit by IP
		$ip  = $request->getClientIp() ?? 'unknown';
		$key = hash('sha256', 'anon:' . $ip);
		return [$key, 'default', self::LIMITS['default']];
	}

	private function currentWindowStart(): int
	{
		return (int) (floor(time() / self::WINDOW_SECONDS) * self::WINDOW_SECONDS);
	}
}
