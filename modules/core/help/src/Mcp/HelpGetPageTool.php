<?php
declare(strict_types=1);

namespace Shipard\Module\Core\Help\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Module\Core\Help\HelpLibrary;

/**
 * Čtecí MCP nástroj: vrátí celý text jedné stránky uživatelské dokumentace.
 *
 * Stránky jsou psané tak, aby se vracely celé — jedna stránka = jedna úloha,
 * jednotky kB (docs/help-authoring.md §2). Proto tu není žádné chunkování
 * ani výběr části textu.
 */
final class HelpGetPageTool implements McpTool
{
	public function __construct(private readonly ?HelpLibrary $library = null) {}

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'help_get_page';
	}

	public function description(): string
	{
		return 'Vrátí celý text stránky uživatelské dokumentace podle cesty, '
			. 'kterou dal help_search (např. `posta/kontrola-vytezeni.md`). '
			. 'Postup uživateli podávej podle téhle stránky — názvy tlačítek '
			. 'a stavů opisuj přesně, jak jsou v ní napsané, protože tak se '
			. 'jmenují v rozhraní. Cestu si nevymýšlej; když ji neznáš, '
			. 'zavolej nejdřív help_search.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'path' => ['type' => 'string', 'description' => 'Cesta ke stránce relativně ke help/, např. `slovnicek.md` nebo `posta/prijem-posty.md`.'],
			],
			'required' => ['path'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if (!array_key_exists('path', $arguments)) {
			throw new \InvalidArgumentException('Missing required parameter: path');
		}

		$path = trim((string) $arguments['path']);
		$library = $this->library ?? HelpLibrary::default();
		$page = $library->page($path);

		if ($page === null) {
			return [
				'summary' => "Stránka \"{$path}\" v dokumentaci není. Najdi si ji přes help_search — cestu si nevymýšlej.",
				'items' => [],
				'pagination' => ['limit' => 1, 'offset' => 0, 'returned' => 0, 'has_more' => false],
			];
		}

		return [
			'summary' => "Dokumentace — {$page->title} ({$page->path})",
			'items' => [[
				'ref'   => ['type' => 'help_page', 'id' => $page->path],
				'label' => $page->title,
				'text'  => $page->body,
			]],
			'pagination' => ['limit' => 1, 'offset' => 0, 'returned' => 1, 'has_more' => false],
		];
	}
}
