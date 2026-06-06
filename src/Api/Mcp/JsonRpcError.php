<?php
declare(strict_types=1);

namespace Shipard\Api\Mcp;

/**
 * JSON-RPC 2.0 chybové kódy + tovární metoda na error envelope. Bez vazby na
 * doménu — drží jen protokolovou vrstvu.
 */
final class JsonRpcError
{
	public const int PARSE_ERROR      = -32700;
	public const int INVALID_REQUEST  = -32600;
	public const int METHOD_NOT_FOUND = -32601;
	public const int INVALID_PARAMS   = -32602;
	public const int INTERNAL_ERROR   = -32603;

	/**
	 * Lidsky čitelná zpráva pro daný kód (defaultní; volající ji může přebít).
	 */
	public static function message(int $code): string
	{
		return match ($code) {
			self::PARSE_ERROR      => 'Parse error',
			self::INVALID_REQUEST  => 'Invalid request',
			self::METHOD_NOT_FOUND => 'Method not found',
			self::INVALID_PARAMS   => 'Invalid params',
			default                => 'Internal error',
		};
	}

	/**
	 * @return array{jsonrpc:string,id:mixed,error:array{code:int,message:string,data?:mixed}}
	 */
	public static function envelope(mixed $id, int $code, ?string $message = null, mixed $data = null): array
	{
		$error = ['code' => $code, 'message' => $message ?? self::message($code)];
		if ($data !== null) {
			$error['data'] = $data;
		}
		return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
	}
}
