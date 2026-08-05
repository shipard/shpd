<?php
declare(strict_types=1);

namespace Shipard\Module\Core\Help\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Module\Core\Help\HelpLibrary;

/**
 * Čtecí MCP nástroj: hledá v uživatelské dokumentaci (`help/`) stránky
 * s návody, jak se co v Shipardu dělá. Vrací kandidáty s `ref`; celou
 * stránku dodá {@see HelpGetPageTool}.
 *
 * Nedotazuje DB — zdrojem jsou markdown soubory aplikace, tedy stejný
 * obsah, jaký čte člověk na GitHubu.
 */
final class HelpSearchTool implements McpTool
{
	private const int DEFAULT_LIMIT = 5;
	private const int MAX_LIMIT = 20;

	public function __construct(private readonly ?HelpLibrary $library = null) {}

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'help_search';
	}

	public function description(): string
	{
		return 'Najde stránky uživatelské dokumentace Shipardu — návody, jak se '
			. 'v aplikaci co dělá, co znamenají stavy a názvy v rozhraní a co '
			. 'Shipard zatím neumí. Použij VŽDY, když se uživatel ptá „jak se '
			. 'dělá X", „kde najdu X", „co znamená X" nebo jestli něco Shipard '
			. 'umí — odpovídej podle dokumentace, ne z vlastní představy o tom, '
			. 'jak by aplikace mohla fungovat. Vrací seznam stránek; celý text '
			. 'stránky si pak vyžádej nástrojem help_get_page. NEpoužívej pro '
			. 'dotazy na konkrétní data uživatele (kolik mám nezaplacených '
			. 'faktur, kdo je dodavatel X) — na ta jsou documents_search, '
			. 'documents_aggregate a persons_search.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => ['type' => 'string', 'description' => 'Čeho se dotaz týká, klidně slovy uživatele: „vytěžení faktury", „storno", „přiznání k DPH". Diakritika ani velikost písmen nehraje roli.'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
			],
			'required' => ['query'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if (!array_key_exists('query', $arguments)) {
			throw new \InvalidArgumentException('Missing required parameter: query');
		}

		$query = trim((string) $arguments['query']);
		$limit = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));

		$library = $this->library ?? HelpLibrary::default();
		$hits = $library->search($query, $limit);

		$items = array_map(static function (array $hit): array {
			return [
				'ref'     => ['type' => 'help_page', 'id' => $hit['page']->path],
				'label'   => $hit['page']->title,
				'text'    => $hit['page']->summary,
			];
		}, $hits);

		return [
			'summary' => $items === []
				? "V dokumentaci není stránka k \"{$query}\". Neodpovídej dohadem — řekni uživateli, že to v dokumentaci není, a případně ho odkaž na podporu."
				: 'Nalezeno ' . count($items) . " stránek k \"{$query}\". Celý text si vyžádej přes help_get_page s cestou z #id.",
			'items' => $items,
			'pagination' => [
				'limit'    => $limit,
				'offset'   => 0,
				'returned' => count($items),
				'has_more' => false,
			],
		];
	}
}
