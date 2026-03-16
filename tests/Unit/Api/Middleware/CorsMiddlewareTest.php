<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Middleware;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Middleware\CorsMiddleware;
use Shipard\Api\Request;
use Shipard\Api\Response;

class CorsMiddlewareTest extends TestCase
{
	private CorsMiddleware $cors;

	protected function setUp(): void
	{
		$this->cors = new CorsMiddleware();
	}

	private function req(string $method, string $path = '/api/v1/items'): Request
	{
		return Request::fromArray($method, $path, [], '', []);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	// ── OPTIONS preflight ─────────────────────────────────────────────────────

	public function testOptionsRequestReturns204Response(): void
	{
		$result = $this->cors->handle($this->req('OPTIONS'));

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(204, $this->getStatus($result));
	}

	public function testOptionsResponseHasCorsHeaders(): void
	{
		$result = $this->cors->handle($this->req('OPTIONS'));

		$this->assertNotNull($result);
		$headers = $result->getHeaders();

		$this->assertArrayHasKey('Access-Control-Allow-Origin',  $headers);
		$this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
		$this->assertArrayHasKey('Access-Control-Allow-Headers', $headers);
		$this->assertArrayHasKey('Access-Control-Max-Age',       $headers);
	}

	public function testOptionsResponseAllowsExpectedMethods(): void
	{
		$result   = $this->cors->handle($this->req('OPTIONS'));
		$methods  = $result->getHeaders()['Access-Control-Allow-Methods'];

		foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
			$this->assertStringContainsString($method, $methods);
		}
	}

	public function testOptionsResponseAllowsExpectedHeaders(): void
	{
		$result  = $this->cors->handle($this->req('OPTIONS'));
		$allowed = $result->getHeaders()['Access-Control-Allow-Headers'];

		$this->assertStringContainsString('Authorization',    $allowed);
		$this->assertStringContainsString('Content-Type',     $allowed);
		$this->assertStringContainsString('Accept-Language',  $allowed);
	}

	// ── Non-OPTIONS: returns null (continue pipeline) ─────────────────────────

	public function testGetRequestReturnsNull(): void
	{
		$this->assertNull($this->cors->handle($this->req('GET')));
	}

	public function testPostRequestReturnsNull(): void
	{
		$this->assertNull($this->cors->handle($this->req('POST')));
	}

	public function testDeleteRequestReturnsNull(): void
	{
		$this->assertNull($this->cors->handle($this->req('DELETE')));
	}

	// ── applyTo() ─────────────────────────────────────────────────────────────

	public function testApplyToAddsHeadersToExistingResponse(): void
	{
		$base    = Response::success(['id' => 1]);
		$result  = $this->cors->applyTo($base);
		$headers = $result->getHeaders();

		$this->assertArrayHasKey('Access-Control-Allow-Origin',  $headers);
		$this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
	}

	public function testApplyToDoesNotMutateOriginalResponse(): void
	{
		$base   = Response::success(['id' => 1]);
		$result = $this->cors->applyTo($base);

		$this->assertEmpty($base->getHeaders());
		$this->assertNotEmpty($result->getHeaders());
	}

	public function testApplyToErrorResponseAddsHeaders(): void
	{
		$base   = Response::error('NOT_FOUND', 'Not found', 404);
		$result = $this->cors->applyTo($base);

		$this->assertArrayHasKey('Access-Control-Allow-Origin', $result->getHeaders());
	}
}
