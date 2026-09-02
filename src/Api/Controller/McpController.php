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
use Shipard\Core\Version;

/**
 * MCP server přes Streamable HTTP (single endpoint POST /api/v1/_mcp).
 * JSON-RPC 2.0 vrstva: initialize / notifications/initialized / tools/list /
 * tools/call. Vlastní mapování doménové obálky nástroje na MCP wire formát.
 */
class McpController
{
	/** Nominální protocolVersion, když ji klient v `initialize` nepošle. */
	private const string PROTOCOL_VERSION = '2025-06-18';

	/** Verze appky pro serverInfo. */
	private const string SERVER_VERSION = Version::VERSION;

	/**
	 * `$readOnly` = DS ve stavu `read_only` (#56 D5): `tools/list` vrací jen
	 * read-tier nástroje a `tools/call` na zápisový nástroj vrací JSON-RPC
	 * chybu — ne HTTP 403, MCP klienti čtou JSON-RPC.
	 */
	public function __construct(
		private readonly McpToolRegistry $registry,
		private readonly bool $readOnly = false,
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
		$visible = $this->readOnly ? $this->registry->readOnlyView() : $this->registry;
		$tools = array_map(static fn(McpTool $t) => [
			'name'        => $t->name(),
			'description' => $t->description(),
			'inputSchema' => $t->inputSchema(),
		], $visible->all());

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
		if ($this->readOnly && !$tool->isReadOnly()) {
			// Explicitní důvod, ne „Unknown tool" — klient má vědět, že nástroj
			// existuje a vrátí se, až DS přejde do active.
			return Response::raw(
				JsonRpcError::envelope($r->id, JsonRpcError::INVALID_PARAMS, "Tool {$tool->name()} is not available: data source is read-only"),
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

	/**
	 * Jedna položka do textového kanálu: `#id popisek — doplňky`. `#id` jen
	 * když položka nese `ref` (agregační skupiny typu „měsíc" ho nemají).
	 * Doplňky jsou volitelné klíče, které nástroje plní — bez `value`/
	 * `share_pct` by byl žebříček z `documents_aggregate` v textovém kanálu
	 * seznam popisků bez čísel.
	 *
	 * Popisek: `label`, s fallbackem na `full_name` (osoby). `text` je
	 * volitelný víceřádkový obsah položky, který se připojí pod hlavičku —
	 * bez něj by nástroj vracející text (help_get_page) do textového kanálu
	 * nedostal nic a `structuredContent` čtou jen někteří klienti.
	 */
	private function compactLine(array $item): string
	{
		$id    = $item['ref']['id'] ?? null;
		$label = trim((string) ($item['label'] ?? $item['full_name'] ?? ''));
		$head  = $id !== null ? '#' . (string) $id . ' ' . $label : $label;

		$parts = [];
		if (!empty($item['company_id'])) {
			$parts[] = 'IČO ' . (string) $item['company_id'];
		}
		if (!empty($item['vat_id'])) {
			$parts[] = 'DIČ ' . (string) $item['vat_id'];
		}
		if (isset($item['value'])) {
			$parts[] = trim((string) $item['value'] . ' ' . (string) ($item['currency'] ?? ''));
		}
		if (isset($item['share_pct'])) {
			$parts[] = (string) $item['share_pct'] . ' %';
		}

		$line = trim(implode(' — ', [$head, implode(', ', $parts)]), " —");
		$text = trim((string) ($item['text'] ?? ''));
		if ($text === '') {
			return $line;
		}
		return $line === '' ? $text : $line . "\n" . $text;
	}

	private function result(mixed $id, array $result): Response
	{
		return Response::raw(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
	}
}
