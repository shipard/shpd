<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\McpController;
use Shipard\Api\Mcp\JsonRpcError;
use Shipard\Api\Mcp\McpToolRegistry;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\Mcp\PersonsSearchTool;

class McpControllerTest extends TestCase
{
	private function buildRequest(?array $body): Request
	{
		return Request::fromArray(
			'POST',
			'/api/v1/_mcp',
			[],
			$body !== null ? (string) json_encode($body) : '',
			['HTTP_HOST' => 'localhost'],
		);
	}

	private function rawBodyRequest(string $raw): Request
	{
		return Request::fromArray('POST', '/api/v1/_mcp', [], $raw, ['HTTP_HOST' => 'localhost']);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	private function controller(?DataSourceConnection $db = null): array
	{
		$registry = new McpToolRegistry();
		$registry->register(new PersonsSearchTool());
		$ctrl = new McpController($registry);
		$db ??= $this->createMock(DataSourceConnection::class);
		return [$ctrl, $db];
	}

	private function call(McpController $ctrl, Request $request, DataSourceConnection $db, bool $auth = true): Response
	{
		$ctx = $auth ? new AuthContext(true, 1, 'api_key') : AuthContext::anonymous();
		return $ctrl->rpc($request, $ctx, $db, [], null);
	}

	// 1. initialize → serverInfo, capabilities.tools, echo protocolVersion
	public function testInitializeEchoesProtocolVersion(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
			'params' => ['protocolVersion' => '2099-01-01', 'capabilities' => [], 'clientInfo' => ['name' => 'x']],
		]), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$p = $resp->getPayload();
		$this->assertSame('2099-01-01', $p['result']['protocolVersion']);
		$this->assertSame('shipard', $p['result']['serverInfo']['name']);
		$this->assertArrayHasKey('tools', $p['result']['capabilities']);
	}

	public function testInitializeFallsBackToNominalVersion(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
		]), $db);
		$this->assertSame('2025-06-18', $resp->getPayload()['result']['protocolVersion']);
	}

	// 2. notifications/initialized → HTTP 202
	public function testNotificationInitializedReturns202(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
		]), $db);
		$this->assertSame(202, $this->getStatus($resp));
	}

	// 3. tools/list → obsahuje persons_search s inputSchema
	public function testToolsListContainsPersonsSearch(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
		]), $db);

		$tools = $resp->getPayload()['result']['tools'];
		$names = array_column($tools, 'name');
		$this->assertContains('persons_search', $names);
		$tool = $tools[array_search('persons_search', $names, true)];
		$this->assertSame('object', $tool['inputSchema']['type']);
		$this->assertContains('query', $tool['inputSchema']['required']);
	}

	// 4. tools/call persons_search → obálka + has_more při překročení limitu
	public function testToolsCallReturnsEnvelopeWithPagination(): void
	{
		// Seed limit+1 řádků (21 při limit 20) → tool ořízne na 20, has_more=true.
		$rows = [];
		for ($i = 1; $i <= 21; $i++) {
			$rows[] = [
				'id' => $i, 'full_name' => "Firma {$i}", 'person_type' => 2,
				'company_id' => "1000000{$i}", 'tax_id' => null, 'vat_id' => "CZ1000000{$i}",
				'email' => "f{$i}@x.cz", 'person_id' => "P{$i}",
			];
		}
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willReturn($rows);

		[$ctrl] = $this->controller($db);
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
			'params' => ['name' => 'persons_search', 'arguments' => ['query' => 'Firma']],
		]), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$result = $resp->getPayload()['result'];
		$this->assertFalse($result['isError']);
		$sc = $result['structuredContent'];
		$this->assertCount(20, $sc['items']);
		$this->assertTrue($sc['pagination']['has_more']);
		$this->assertSame(['type' => 'person', 'id' => 1], $sc['items'][0]['ref']);
		$this->assertSame('company', $sc['items'][0]['person_type']);
		$this->assertSame('CZ10000001', $sc['items'][0]['vat_id']);
		// content text vždy přítomen
		$this->assertNotEmpty($result['content'][0]['text']);
	}

	// 5. tools/call neznámý nástroj → -32602
	public function testToolsCallUnknownToolIsInvalidParams(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
			'params' => ['name' => 'does_not_exist', 'arguments' => []],
		]), $db);

		$this->assertSame(JsonRpcError::INVALID_PARAMS, $resp->getPayload()['error']['code']);
	}

	// 6. tools/call persons_search bez query → -32602
	public function testToolsCallMissingQueryIsInvalidParams(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
			'params' => ['name' => 'persons_search', 'arguments' => []],
		]), $db);

		$this->assertSame(JsonRpcError::INVALID_PARAMS, $resp->getPayload()['error']['code']);
	}

	// 7. vykonávací chyba → result.isError (ne JSON-RPC error)
	public function testToolExecutionErrorReturnsIsError(): void
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchAll')->willThrowException(new \RuntimeException('boom'));

		[$ctrl] = $this->controller($db);
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
			'params' => ['name' => 'persons_search', 'arguments' => ['query' => 'x']],
		]), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$p = $resp->getPayload();
		$this->assertArrayNotHasKey('error', $p);
		$this->assertTrue($p['result']['isError']);
	}

	// 8. bez tokenu → HTTP 401
	public function testUnauthenticatedReturns401(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list',
		]), $db, auth: false);

		$this->assertSame(401, $this->getStatus($resp));
	}

	// 9. tělo není JSON → -32700
	public function testInvalidJsonBodyIsParseError(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->rawBodyRequest('{ not json'), $db);

		$this->assertSame(200, $this->getStatus($resp));
		$this->assertSame(JsonRpcError::PARSE_ERROR, $resp->getPayload()['error']['code']);
	}

	public function testUnknownMethodIsMethodNotFound(): void
	{
		[$ctrl, $db] = $this->controller();
		$resp = $this->call($ctrl, $this->buildRequest([
			'jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/list',
		]), $db);

		$this->assertSame(JsonRpcError::METHOD_NOT_FOUND, $resp->getPayload()['error']['code']);
	}
}
