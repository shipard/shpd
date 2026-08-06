<?php
declare(strict_types=1);

namespace Shipard\Module\Core\Help;

/**
 * Knihovna uživatelské dokumentace — čte markdown soubory z `help/`,
 * parsuje jejich YAML hlavičku a vyhledává v nich.
 *
 * Bez indexu a bez cache: stránek je řád desítek a každá má jednotky kB,
 * takže načtení a oskórování je pod milisekundu. Generovaný index by navíc
 * mohl přestat odpovídat obsahu — zdrojem pravdy jsou přímo soubory.
 *
 * **Hledání ignoruje diakritiku i velikost písmen.** U české dokumentace
 * je to podmínka funkčnosti: uživatel napíše „vytezeni" stejně často jako
 * „vytěžení". Normalizace je vlastní (`normalize()`), bez závislosti na
 * ext-intl.
 *
 * Váhy skóre (klesající): `keywords` > `title` > `summary` > tělo. Klíčová
 * slova váží nejvíc, protože jsou v hlavičce psaná právě pro dohledatelnost,
 * včetně hovorových variant.
 */
final class HelpLibrary
{
	private const int DEFAULT_LIMIT = 5;
	private const int MAX_LIMIT = 20;

	/** Váhy zásahu podle místa výskytu. */
	private const int SCORE_KEYWORD_EXACT = 14;
	private const int SCORE_KEYWORD_PART  = 8;
	private const int SCORE_TITLE         = 6;
	private const int SCORE_SUMMARY       = 3;
	private const int SCORE_BODY          = 1;

	/** Kratší tokeny (předložky, „a", „v") nehledáme. */
	private const int MIN_TOKEN_LEN = 2;

	/** Mapa pro odstranění diakritiky — jen znaky, které čeština potřebuje. */
	private const array DIACRITICS = [
		'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
		'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
		'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
	];

	/** @var list<HelpPage>|null */
	private ?array $pages = null;

	public function __construct(private readonly string $helpDir) {}

	/** Knihovna nad `help/` v korenu repozitáře aplikace. */
	public static function default(): self
	{
		return new self(dirname(__DIR__, 4) . '/help');
	}

	/**
	 * Všechny stránky, seřazené podle cesty. `README.md` je rozcestník,
	 * ne stránka dokumentace — vynechává se (stejně jako v help-index.py).
	 *
	 * @return list<HelpPage>
	 */
	public function pages(): array
	{
		if ($this->pages !== null) {
			return $this->pages;
		}

		$dir = realpath($this->helpDir);
		if ($dir === false || !is_dir($dir)) {
			return $this->pages = [];
		}

		$files = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		);
		foreach ($it as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'md') continue;
			if ($file->getFilename() === 'README.md') continue;
			$files[] = $file->getPathname();
		}
		sort($files);

		$pages = [];
		foreach ($files as $abs) {
			$rel = ltrim(str_replace($dir, '', $abs), '/');
			$page = $this->parse($rel, (string) file_get_contents($abs));
			if ($page !== null) {
				$pages[] = $page;
			}
		}

		return $this->pages = $pages;
	}

	/**
	 * Stránka podle cesty relativní ke `help/`. Vrací null, když neexistuje
	 * nebo když cesta míří mimo `help/` (ochrana proti `../`).
	 */
	public function page(string $path): ?HelpPage
	{
		$path = ltrim(trim($path), '/');
		if ($path === '' || str_contains($path, '..')) {
			return null;
		}
		foreach ($this->pages() as $page) {
			if ($page->path === $path) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * Stránky odpovídající dotazu, od nejlepší. Stránky s nulovým skóre
	 * se nevrací — modelu je lepší říct „nic jsem nenašla" než mu podstrčit
	 * náhodnou stránku.
	 *
	 * @return list<array{page: HelpPage, score: int}>
	 */
	public function search(string $query, int $limit = self::DEFAULT_LIMIT): array
	{
		$limit  = max(1, min(self::MAX_LIMIT, $limit));
		$tokens = $this->tokenize($query);
		if ($tokens === []) {
			return [];
		}

		$hits = [];
		foreach ($this->pages() as $page) {
			$score = $this->score($page, $tokens);
			if ($score > 0) {
				$hits[] = ['page' => $page, 'score' => $score];
			}
		}

		usort($hits, static function (array $a, array $b): int {
			return $b['score'] <=> $a['score']
				?: strcmp($a['page']->path, $b['page']->path);
		});

		return array_slice($hits, 0, $limit);
	}

	/** @param list<string> $tokens */
	private function score(HelpPage $page, array $tokens): int
	{
		$title    = $this->normalize($page->title);
		$summary  = $this->normalize($page->summary);
		$body     = $this->normalize($page->body);
		$keywords = array_map([$this, 'normalize'], $page->keywords);

		$score = 0;
		foreach ($tokens as $token) {
			foreach ($keywords as $kw) {
				if ($kw === $token) {
					$score += self::SCORE_KEYWORD_EXACT;
					continue 2;
				}
			}
			foreach ($keywords as $kw) {
				if (str_contains($kw, $token)) {
					$score += self::SCORE_KEYWORD_PART;
					continue 2;
				}
			}
			if (str_contains($title, $token)) {
				$score += self::SCORE_TITLE;
				continue;
			}
			if (str_contains($summary, $token)) {
				$score += self::SCORE_SUMMARY;
				continue;
			}
			if (str_contains($body, $token)) {
				$score += self::SCORE_BODY;
			}
		}

		return $score;
	}

	/**
	 * Tokeny dotazu, deduplikované.
	 *
	 * `array_map('strval', ...)` na konci není kosmetika: PHP převádí
	 * číselné klíče pole na int, takže bez přetypování by dotaz s číslem
	 * („účet 343“, „nebývá to maska 518“) poslal do `str_contains()`
	 * int a shodil hledání na TypeError.
	 *
	 * @return list<string>
	 */
	private function tokenize(string $query): array
	{
		$parts = preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($query)) ?: [];
		$tokens = [];
		foreach ($parts as $part) {
			if (mb_strlen($part) >= self::MIN_TOKEN_LEN) {
				$tokens[$part] = true;
			}
		}
		return array_map('strval', array_keys($tokens));
	}

	/** Malá písmena bez diakritiky. */
	private function normalize(string $s): string
	{
		return strtr(mb_strtolower($s, 'UTF-8'), self::DIACRITICS);
	}

	/**
	 * Rozparsuje YAML hlavičku a tělo. Stránka bez hlavičky nebo bez
	 * `title` se přeskočí — pre-commit hook (`scripts/help-index.py --check`)
	 * takový soubor commitnout nepustí, takže je to jen pojistka.
	 */
	private function parse(string $path, string $raw): ?HelpPage
	{
		$raw = str_replace("\r\n", "\n", $raw);
		if (!str_starts_with($raw, "---\n")) {
			return null;
		}
		$end = strpos($raw, "\n---\n", 3);
		if ($end === false) {
			return null;
		}

		$head = substr($raw, 4, $end - 3);
		$body = ltrim(substr($raw, $end + 5), "\n");

		$meta = [];
		foreach (explode("\n", $head) as $line) {
			if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m)) continue;
			$meta[$m[1]] = $this->parseValue($m[2]);
		}

		$title = is_string($meta['title'] ?? null) ? trim($meta['title']) : '';
		if ($title === '') {
			return null;
		}

		return new HelpPage(
			path: $path,
			title: $title,
			summary: is_string($meta['summary'] ?? null) ? trim($meta['summary']) : '',
			keywords: $this->toList($meta['keywords'] ?? null),
			related: $this->toList($meta['related'] ?? null),
			body: $body,
		);
	}

	/** Skalár, nebo inline seznam `[a, b]`. */
	private function parseValue(string $raw): string|array
	{
		$raw = trim($raw);
		if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
			$inner = trim(substr($raw, 1, -1));
			if ($inner === '') {
				return [];
			}
			return array_values(array_filter(array_map('trim', explode(',', $inner)), static fn(string $v) => $v !== ''));
		}
		return $raw;
	}

	/** @return list<string> */
	private function toList(mixed $value): array
	{
		if (is_array($value)) {
			return array_values(array_map(static fn(mixed $v) => (string) $v, $value));
		}
		if (is_string($value) && trim($value) !== '') {
			return [trim($value)];
		}
		return [];
	}
}
