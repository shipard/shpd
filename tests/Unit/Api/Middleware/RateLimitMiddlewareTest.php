<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Middleware;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Middleware\RateLimitMiddleware;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Core\Database\DataSourceConnection;

class TestableRateLimitMiddleware extends RateLimitMiddleware
{
	/** @var array<string, int>  key => count in current window */
	private array $store = [];

	/** Override window start to a fixed value for deterministic tests. */
	public int $fixedWindowStart;

	public function __construct()
	{
		$this->fixedWindowStart = (int) (floor(time() / 60) * 60);
	}

	protected function getCount(string $key, int $windowStart, DataSourceConnection $db): int
	{
		return $this->store[$key] ?? 0;
	}

	protected function incrementCount(string $key, string $type, int $windowStart, int $current, DataSourceConnection $db): void
	{
		$this->store[$key] = ($this->store[$key] ?? 0) + 1;
	}

	/** Preset the counter for a specific key. */
	public function preset(Request $request, AuthContext $auth, Route $route, int $count): void
	{
		// We need to derive the key the same way handle() does.
		// Use reflection to call the private method.
		$ref    = new \ReflectionClass(RateLimitMiddleware::class);
		$method = $ref->getMethod('resolveKey');
		[$key]  = $method->invoke($this, $request, $auth, $route);
		$this->store[$key] = $count;
	}
}

class RateLimitMiddlewareTest extends TestCase
{
	private DataSourceConnection $db;

	protected function setUp(): void
	{
		$ref      = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();
	}

	private function req(string $method = 'GET', string $path = '/api/v1/items', ?string $ip = null): Request
	{
		$server = $ip !== null ? ['REMOTE_ADDR' => $ip] : [];
		return Request::fromArray($method, $path, [], '', $server);
	}

	private function route(string $ctrl = 'crud', string $action = 'list'): Route
	{
		return new Route($ctrl, $action);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	// ── Within limits ─────────────────────────────────────────────────────────

	public function testRequestBelowLimitReturnsNull(): void
	{
		$mw     = new TestableRateLimitMiddleware();
		$result = $mw->handle($this->req(), AuthContext::anonymous(), $this->route(), $this->db);

		$this->assertNull($result);
	}

	public function testAfterSuccessfulRequestHeadersArePopulated(): void
	{
		$mw = new TestableRateLimitMiddleware();
		$mw->handle($this->req(), AuthContext::anonymous(), $this->route(), $this->db);

		$headers = $mw->getHeaders();
		$this->assertArrayHasKey('X-RateLimit-Limit',     $headers);
		$this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
		$this->assertArrayHasKey('X-RateLimit-Reset',     $headers);
	}

	public function testAuthenticatedSessionTokenUsesSessionLimit(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = new AuthContext(true, 1, 'session', 'shpd_st_sometoken');
		$mw->handle($this->req(), $auth, $this->route(), $this->db);

		$this->assertSame('300', $mw->getHeaders()['X-RateLimit-Limit']);
	}

	public function testAuthenticatedApiKeyUsesApiKeyLimit(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = new AuthContext(true, 1, 'api_key', 'shpd_ak_somekey');
		$mw->handle($this->req(), $auth, $this->route(), $this->db);

		$this->assertSame('1000', $mw->getHeaders()['X-RateLimit-Limit']);
	}

	public function testLoginEndpointUsesLoginLimit(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req('POST', '/api/v1/_auth/login', '127.0.0.1');
		$mw->handle($req, $auth, $this->route('auth', 'login'), $this->db);

		$this->assertSame('10', $mw->getHeaders()['X-RateLimit-Limit']);
	}

	// ── Over limit ────────────────────────────────────────────────────────────

	public function testRequestOverLimitReturns429(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '192.168.1.1');
		$rt   = $this->route();

		// Preset counter to the limit (60 for anonymous)
		$mw->preset($req, $auth, $rt, 60);

		$result = $mw->handle($req, $auth, $rt, $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(429, $this->getStatus($result));
	}

	public function test429ResponseHasRateLimitCode(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '10.0.0.1');
		$rt   = $this->route();

		$mw->preset($req, $auth, $rt, 60);

		$result  = $mw->handle($req, $auth, $rt, $this->db);
		$payload = $result->getPayload();

		$this->assertSame('RATE_LIMITED', $payload['error']['code']);
	}

	public function test429ResponseHasRateLimitHeaders(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '10.0.0.2');
		$rt   = $this->route();

		$mw->preset($req, $auth, $rt, 60);
		$result = $mw->handle($req, $auth, $rt, $this->db);

		$this->assertNotNull($result);
		$headers = $result->getHeaders();
		$this->assertArrayHasKey('X-RateLimit-Limit',     $headers);
		$this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
		$this->assertArrayHasKey('X-RateLimit-Reset',     $headers);
		$this->assertSame('0', $headers['X-RateLimit-Remaining']);
	}

	public function testLoginEndpointOverLimitReturns429(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req('POST', '/api/v1/_auth/login', '1.2.3.4');
		$rt   = $this->route('auth', 'login');

		// Login limit is 10
		$mw->preset($req, $auth, $rt, 10);

		$result = $mw->handle($req, $auth, $rt, $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(429, $this->getStatus($result));
	}

	// ── Remaining count ───────────────────────────────────────────────────────

	public function testRemainingDecrementsWithEachRequest(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '10.0.0.5');
		$rt   = $this->route();

		$mw->handle($req, $auth, $rt, $this->db);
		$remaining1 = (int) $mw->getHeaders()['X-RateLimit-Remaining'];

		$mw->handle($req, $auth, $rt, $this->db);
		$remaining2 = (int) $mw->getHeaders()['X-RateLimit-Remaining'];

		$this->assertGreaterThan($remaining2, $remaining1);
	}
}
