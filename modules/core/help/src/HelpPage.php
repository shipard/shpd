<?php
declare(strict_types=1);

namespace Shipard\Module\Core\Help;

/**
 * Jedna stránka uživatelské dokumentace z `help/`.
 *
 * `path` je cesta relativní ke `help/` (např. `posta/prijem-posty.md`) —
 * slouží jako stabilní identifikátor pro `ref` i pro `help_get_page`.
 * `body` je markdown bez YAML hlavičky; hlavička sama je rozparsovaná
 * do ostatních polí. Formát hlavičky: docs/help-authoring.md §3.
 */
final readonly class HelpPage
{
	/**
	 * @param list<string> $keywords
	 * @param list<string> $related
	 */
	public function __construct(
		public string $path,
		public string $title,
		public string $summary,
		public array $keywords,
		public array $related,
		public string $body,
	) {}
}
