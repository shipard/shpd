<?php
declare(strict_types=1);

namespace Shipard\Api\Mcp;

/**
 * Registr MCP nástrojů. Ve fázi 1 plněn in-code v `dispatchMcp` (analogie
 * wiring v `dispatchExchange`); přechod na module-driven registraci přes
 * `module.jsonc` je odložený a nemění tvar nástrojů.
 */
final class McpToolRegistry
{
	/** @var array<string, McpTool> */
	private array $tools = [];

	public function register(McpTool $tool): void
	{
		$this->tools[$tool->name()] = $tool;
	}

	/** @return McpTool[] */
	public function all(): array
	{
		return array_values($this->tools);
	}

	public function get(string $name): ?McpTool
	{
		return $this->tools[$name] ?? null;
	}
}
