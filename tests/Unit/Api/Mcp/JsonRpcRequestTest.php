<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Mcp\JsonRpcError;
use Shipard\Api\Mcp\JsonRpcRequest;

class JsonRpcRequestTest extends TestCase
{
	public function testNullBodyIsParseError(): void
	{
		$this->assertSame(JsonRpcError::PARSE_ERROR, JsonRpcRequest::tryParse(null));
	}

	public function testWrongVersionIsInvalidRequest(): void
	{
		$this->assertSame(
			JsonRpcError::INVALID_REQUEST,
			JsonRpcRequest::tryParse(['jsonrpc' => '1.0', 'method' => 'x', 'id' => 1]),
		);
	}

	public function testMissingMethodIsInvalidRequest(): void
	{
		$this->assertSame(
			JsonRpcError::INVALID_REQUEST,
			JsonRpcRequest::tryParse(['jsonrpc' => '2.0', 'id' => 1]),
		);
	}

	public function testNonArrayParamsIsInvalidParams(): void
	{
		$this->assertSame(
			JsonRpcError::INVALID_PARAMS,
			JsonRpcRequest::tryParse(['jsonrpc' => '2.0', 'method' => 'x', 'id' => 1, 'params' => 'nope']),
		);
	}

	public function testRequestWithId(): void
	{
		$r = JsonRpcRequest::tryParse(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 7, 'params' => ['a' => 1]]);
		$this->assertInstanceOf(JsonRpcRequest::class, $r);
		$this->assertSame(7, $r->id);
		$this->assertSame('tools/list', $r->method);
		$this->assertSame(['a' => 1], $r->params);
		$this->assertFalse($r->isNotification);
	}

	public function testNotificationHasNoId(): void
	{
		$r = JsonRpcRequest::tryParse(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
		$this->assertInstanceOf(JsonRpcRequest::class, $r);
		$this->assertTrue($r->isNotification);
		$this->assertNull($r->id);
	}
}
