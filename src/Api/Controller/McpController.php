<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Mcp\JsonRpcError;
use Shipard\Api\Mcp\JsonRpcRequest;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Api\Mcp\McpToolRegistry;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * MCP server přes Streamable HTTP (single endpoint POST /api/v1/_mcp).
 * JSON-RPC 2.0 vrstva: initialize / notifications/initialized / tools/list /
 * tools/call. Vlastní mapování doménové obálky nástroje na MCP wire formát.
 */
class McpController
{
	/** Nominální protocolVersion, když ji klient v `initialize` nepošle. */
	private const string PROTOCOL_VERSION = '2025-06-18';

	/** Verze appky pro serverInfo (dnes hardcoded i v *VersionCommand). */
	private const string SERVER_VERSION = '0.1.0';

	public function __construct(
		private readonly McpToolRegistry $registry,
	) {}

	public function rpc(
		Request $request,
		AuthContext $auth,
		DataSourceConnection $db,
		array $tables,
		?ConfigRuntime $configRuntime,
	): Response {
		// 1. Auth gate — AuthMiddleware vrací anonymous() bez tokenu (nezamítá),
		//    vynucení přihlášení je tedy na controlleru (transport-level 401).
		if (!$auth->isAuthenticated) {
			return Response::error('UNAUTHORIZED', 'Authentication required', 401);
		}

		// 2. Parse JSON-RPC obálky.
		$parsed = JsonRpcRequest::tryParse($request->getBody());
		if (is_int($parsed)) {
			// Chybu tvaru hlásíme s id=null (skutečné id neznáme). HTTP 200.
			return Response::raw(JsonRpcError::envelope(null, $parsed), 200);
		}

		// 3. Dispatch dle metody.
		return match ($parsed->method) {
			'initialize'                => $this->initialize($parsed),
			'notifications/initialized' => Response::raw(null, 202),
			'tools/list'                => $this->toolsList($parsed),
			'tools/call'                => $this->toolsCall(
				$parsed,
				new McpInvocationContext($auth, $db, $tables, $configRuntime),
			),
			default => Response::raw(
				JsonRpcError::envelope($parsed->id, JsonRpcError::METHOD_NOT_FOUND),
				200,
			),
		};
	}

	private function initialize(JsonRpcRequest $r): Response
	{
		// protocolVersion negociujeme echem klientovy hodnoty; fallback při
		// chybějící/neplatné hodnotě na naši nominální konstantu.
		$clientVersion = $r->params['protocolVersion'] ?? null;
		$protocolVersion = is_string($clientVersion) && $clientVersion !== ''
			? $clientVersion
			: self::PROTOCOL_VERSION;

		return $this->result($r->id, [
			'protocolVersion' => $protocolVersion,
			// Prázdný objekt, ne pole — json_encode([]) by dalo [] místo {}.
			'capabilities'    => ['tools' => new \stdClass()],
			'serverInfo'      => ['name' => 'shipard', 'version' => self::SERVER_VERSION],
		]);
	}

	private function toolsList(JsonRpcRequest $r): Response
	{
		$tools = array_map(static fn(McpTool $t) => [
			'name'        => $t->name(),
			'description' => $t->description(),
			'inputSchema' => $t->inputSchema(),
		], $this->registry->all());

		return $this->result($r->id, ['tools' => $tools]);
	}

	private function toolsCall(JsonRpcRequest $r, McpInvocationContext $ctx): Response
	{
		$name = $r->params['name'] ?? null;
		$args = $r->params['arguments'] ?? [];

		// Neznámý nástroj nebo špatný tvar params → protokolová chyba -32602.
		$tool = is_string($name) ? $this->registry->get($name) : null;
		if ($tool === null) {
			return Response::raw(
				JsonRpcError::envelope($r->id, JsonRpcError::INVALID_PARAMS, "Unknown tool: " . (is_string($name) ? $name : '(missing)')),
				200,
			);
		}
		if (!is_array($args)) {
			return Response::raw(
				JsonRpcError::envelope($r->id, JsonRpcError::INVALID_PARAMS, 'Parameter "arguments" must be an object'),
				200,
			);
		}

		try {
			$envelope = $tool->call($args, $ctx);
		} catch (\InvalidArgumentException $e) {
			// Chybějící/neplatný povinný argument = protokolová chyba.
			return Response::raw(
				JsonRpcError::envelope($r->id, JsonRpcError::INVALID_PARAMS, $e->getMessage()),
				200,
			);
		} catch (\Throwable $e) {
			// Vykonávací chyba (např. DB) ≠ protokolová: result.isError=true,
			// ať na to LLM umí zareagovat.
			return $this->result($r->id, [
				'content' => [['type' => 'text', 'text' => 'Nástroj selhal: ' . $e->getMessage()]],
				'isError' => true,
			]);
		}

		return $this->result($r->id, $this->toWire($envelope));
	}

	/**
	 * Doménová obálka {summary, items, pagination} → MCP tools/call result.
	 * `content` text vždy (univerzální), `structuredContent` aditivně.
	 */
	private function toWire(array $envelope): array
	{
		$lines = [(string) ($envelope['summary'] ?? '')];
		foreach ($envelope['items'] ?? [] as $item) {
			$lines[] = $this->compactLine($item);
		}

		return [
			'content'           => [['type' => 'text', 'text' => implode("\n", array_filter($lines, static fn($l) => $l !== ''))]],
			'structuredContent' => $envelope,
			'isError'           => false,
		];
	}

	private function compactLine(array $item): string
	{
		$id = $item['ref']['id'] ?? null;
		$parts = ['#' . (string) $id . ' ' . (string) ($item['full_name'] ?? '')];
		if (!empty($item['company_id'])) {
			$parts[] = 'IČO ' . (string) $item['company_id'];
		}
		if (!empty($item['vat_id'])) {
			$parts[] = 'DIČ ' . (string) $item['vat_id'];
		}
		return trim(implode(' — ', [array_shift($parts), implode(', ', $parts)]), " —");
	}

	private function result(mixed $id, array $result): Response
	{
		return Response::raw(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
	}
}
