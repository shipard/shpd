<?php
declare(strict_types=1);

namespace Shipard\Api\Mcp;

use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Feed\FeedCollector;

/**
 * Čtecí MCP nástroj: aktuální karty feedu upozornění a návrhů (tentýž sběr
 * jako dashboard — `FeedCollector`), volitelně filtrované na jednu sekci
 * navigace (UI shells Fáze 5, D3). Model si feed tahá sám (pull) — nic se
 * mu netlačí do promptu.
 *
 * Jazyk a AlertCheckRegistry nejsou v `McpInvocationContext` — injektují se
 * při registraci v `buildMcpRegistry()` (vzor `ReportToolSupport`). Bez
 * registru by alertové karty měly `navSection = null` a sekční filtr by je
 * minul.
 */
final class FeedCardsTool implements McpTool
{
	public function __construct(
		private readonly ?string $lang = null,
		private readonly ?AlertCheckRegistry $alertRegistry = null,
	) {}

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'feed_cards';
	}

	public function description(): string
	{
		return 'Vrátí aktuální upozornění a návrhy akcí (karty dashboard feedu): '
			. 'alerty, návrhy z došlé pošty a další. Použij, když se uživatel ptá, '
			. 'co na něj čeká, co je potřeba vyřešit nebo co je nového. Volitelný '
			. 'parametr `section` omezí karty na jednu sekci navigace '
			. '(např. purchase, sales, accounting); bez něj vrací celý feed. '
			. 'Nástroj jen čte — akce karet vykonává uživatel v UI.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'section' => [
					'type'        => 'string',
					'description' => 'Id sekce navigace (např. purchase, sales, accounting) — vrátí jen karty této sekce.',
				],
			],
			'required' => [],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		$section = isset($arguments['section']) ? trim((string) $arguments['section']) : '';

		[$cards] = (new FeedCollector())->collect(
			$ctx->db,
			$ctx->config,
			$this->lang ?? 'en',
			$this->alertRegistry,
			$ctx->tables,
		);

		if ($section !== '') {
			$cards = array_values(array_filter(
				$cards,
				static fn (array $c): bool => ($c['navSection'] ?? null) === $section,
			));
		}

		$items = array_map(static fn (array $c): array => [
			'kind'       => $c['kind'] ?? null,
			'title'      => $c['title'] ?? null,
			'subtitle'   => $c['subtitle'] ?? null,
			'navSection' => $c['navSection'] ?? null,
			'timestamp'  => $c['timestamp'] ?? null,
		], $cards);

		$shown = count($items);
		$scope = $section !== '' ? " v sekci \"{$section}\"" : '';

		return [
			'summary' => $shown === 0
				? "Žádné karty{$scope}."
				: "Nalezeno {$shown} karet{$scope}.",
			'items' => $items,
			'pagination' => [
				'limit'    => FeedCollector::MAX_CARDS,
				'offset'   => 0,
				'returned' => $shown,
				'has_more' => false,
			],
		];
	}
}
