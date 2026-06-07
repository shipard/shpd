<?php
declare(strict_types=1);

namespace Shipard\Api\Mcp;

/**
 * MCP nástroj — LLM-přívětivý obal nad doménovou operací. Nástroj vlastní
 * doménovou obálku výstupu (`{summary, items, pagination}`); MCP wire formát
 * (`content`/`structuredContent`) vlastní McpController.
 *
 * Jmenná konvence: `modul_operace` s podtržítkem (`persons_search`), bez tečky.
 */
interface McpTool
{
	/** Unikátní jméno nástroje, vzor [a-z0-9_]. */
	public function name(): string;

	/** Popis pro LLM (kdy použít / nepoužít). */
	public function description(): string;

	/** JSON Schema vstupních argumentů (type: object). */
	public function inputSchema(): array;

	/**
	 * Je nástroj jen čtecí (bez vedlejších efektů)? Chat (Fáze 2b) nabízí
	 * modelu v tool-use smyčce jen read-only nástroje; zápisové/akční nástroje
	 * vyžadují potvrzovací tok a do chatu v1 nejdou.
	 */
	public function isReadOnly(): bool;

	/**
	 * @param array $arguments  argumenty od klienta (dle inputSchema)
	 * @return array doménová obálka {summary, items, pagination}
	 * @throws \InvalidArgumentException při chybějícím/neplatném povinném argumentu (→ -32602)
	 */
	public function call(array $arguments, McpInvocationContext $ctx): array;
}
