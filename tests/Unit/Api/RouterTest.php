<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;

class RouterTest extends TestCase
{
	private Router $router;

	protected function setUp(): void
	{
		$this->router = new Router();
	}

	private function assertRoute(Route $route, string $controller, string $action, ?string $table = null, ?int $id = null): void
	{
		$this->assertSame($controller, $route->controller);
		$this->assertSame($action, $route->action);
		$this->assertSame($table, $route->table);
		$this->assertSame($id, $route->id);
	}

	// Meta endpoints
	public function testMetaTables(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'meta', 'tables');
	}

	public function testMetaTablesSingle(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables/core_system_users', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'meta', 'table', 'core_system_users');
	}

	// OpenAPI
	public function testOpenApiSpec(): void
	{
		$result = $this->router->resolve('/api/v1/_openapi.json', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'openapi', 'spec');
	}

	// Auth endpoints
	public function testAuthLogin(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/login', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'login');
	}

	public function testAuthRefresh(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/refresh', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'refresh');
	}

	public function testAuthLogout(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/logout', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'logout');
	}

	// CRUD list & create
	public function testCrudList(): void
	{
		$result = $this->router->resolve('/api/v1/economy_docs_heads', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'list', 'economy_docs_heads');
	}

	public function testCrudCreate(): void
	{
		$result = $this->router->resolve('/api/v1/economy_docs_heads', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'create', 'economy_docs_heads');
	}

	// CRUD with id
	public function testCrudShow(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/42', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'show', 'core_system_users', 42);
	}

	public function testCrudUpdate(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'PUT');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'update', 'core_system_users', 5);
	}

	public function testCrudPatch(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'PATCH');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'patch', 'core_system_users', 5);
	}

	public function testCrudDelete(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'delete', 'core_system_users', 5);
	}

	// Special endpoints not treated as generic table
	public function testMetaNotTreatedAsTable(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('meta', $result->controller);
	}

	// Error cases
	public function testUnknownPath(): void
	{
		$result = $this->router->resolve('/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertFalse($payload['success']);
		$this->assertSame('NOT_FOUND', $payload['error']['code']);
	}

	public function testMethodNotAllowedOnAuthLogin(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/login', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertSame('METHOD_NOT_ALLOWED', $payload['error']['code']);
	}

	public function testNonNumericIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/users/abc', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertSame('NOT_FOUND', $payload['error']['code']);
	}

	public function testZeroIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/users/0', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testMethodNotAllowedOnCrudList(): void
	{
		$result = $this->router->resolve('/api/v1/some_table', 'DELETE');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testMethodNotAllowedOnOpenApi(): void
	{
		$result = $this->router->resolve('/api/v1/_openapi.json', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testTableNamePreserved(): void
	{
		$result = $this->router->resolve('/api/v1/economy_codebooks_warehouses', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('economy_codebooks_warehouses', $result->table);
	}
}
