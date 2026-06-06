<?php
declare(strict_types=1);

namespace Shipard\Api\Mcp;

/**
 * Parsovaná JSON-RPC 2.0 obálka jednoho requestu. `tryParse()` ověří tvar nad
 * již dekódovaným tělem (`Request::getBody()` vrací `?array`); při neplatném
 * tvaru vrací místo instance JSON-RPC chybový kód.
 *
 * Notifikace = request bez klíče `id` (server na ni nevrací JSON-RPC odpověď).
 */
final readonly class JsonRpcRequest
{
	public function __construct(
		public mixed $id,
		public string $method,
		public array $params,
		public bool $isNotification,
	) {}

	/**
	 * @return self|int  int = JSON-RPC chybový kód při neplatném tvaru
	 */
	public static function tryParse(?array $body): self|int
	{
		// Request::getBody() vrací null jak při chybějícím těle, tak při
		// nevalidním JSON — obojí je pro nás parse error.
		if ($body === null) {
			return JsonRpcError::PARSE_ERROR;
		}

		if (($body['jsonrpc'] ?? null) !== '2.0' || !is_string($body['method'] ?? null)) {
			return JsonRpcError::INVALID_REQUEST;
		}

		$params = $body['params'] ?? [];
		if (!is_array($params)) {
			return JsonRpcError::INVALID_PARAMS;
		}

		return new self(
			id: $body['id'] ?? null,
			method: $body['method'],
			params: $params,
			isNotification: !array_key_exists('id', $body),
		);
	}
}
