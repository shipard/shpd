<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

use Shipard\Core\Logging\ErrorLogger;

/**
 * Sidecar cache LLM klasifikace textů účetní historie (D33) — soubor
 * `<input>.tags.jsonl` vedle vstupu, řádky `{rowTextNorm, tag, promptVersion}`.
 *
 * Proč sidecar a ne DB: klasifikace patří ke **souboru**, ne k datasetu —
 * report se běžně pouští opakovaně (jiné prahy, jiná varianta osnovy) a
 * nesmí přitom platit LLM znovu. Soubor se dá i přenést spolu se vstupem.
 *
 * `tag: null` je platný záznam („model štítek nenašel") a cachuje se.
 * Selhání dávky se **necachuje** — jinak by chyba sítě zamrzla jako
 * legitimní výsledek. Zapisuje se po každé dávce, takže přerušený běh
 * nezahodí, co už bylo zaplacené.
 *
 * Změna promptu = změna `promptVersion` → starší řádky se ignorují (v
 * souboru zůstanou, dokud je někdo nesmaže).
 */
final class TagCache
{
    private function __construct(
        public readonly string $path,
    ) {}

    public static function forInput(string $inputPath): self
    {
        return new self($inputPath . '.tags.jsonl');
    }

    /** Cache v pamětí bez souboru — pro testy a běhy, kde se psát nesmí. */
    public static function inMemory(): self
    {
        return new self('');
    }

    /**
     * Načte cachované štítky pro danou verzi promptu. Poškozený řádek se
     * přeskočí (cache není zdroj pravdy — smí se rozbít).
     *
     * @return array<string, string|null> rowTextNorm → štítek|null
     */
    public function load(string $promptVersion): array
    {
        if ($this->path === '' || !is_file($this->path)) {
            return [];
        }
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            ErrorLogger::warn('TagCache: cache file not readable', ['path' => $this->path]);
            return [];
        }

        $tags = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $entry = json_decode($line, true);
                if (!is_array($entry)
                    || ($entry['promptVersion'] ?? null) !== $promptVersion
                    || !is_string($entry['rowTextNorm'] ?? null)
                ) {
                    continue;
                }
                $tag = $entry['tag'] ?? null;
                // Pozdější řádek přebíjí dřívější (append-only historie).
                $tags[$entry['rowTextNorm']] = is_string($tag) && $tag !== '' ? $tag : null;
            }
        } finally {
            fclose($handle);
        }
        return $tags;
    }

    /**
     * Připíše výsledky jedné dávky.
     *
     * @param array<string, string|null> $tags
     */
    public function append(array $tags, string $promptVersion): void
    {
        if ($this->path === '' || $tags === []) {
            return;
        }
        $lines = '';
        foreach ($tags as $norm => $tag) {
            $lines .= json_encode(
                ['rowTextNorm' => (string) $norm, 'tag' => $tag, 'promptVersion' => $promptVersion],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . "\n";
        }
        if (@file_put_contents($this->path, $lines, FILE_APPEND | LOCK_EX) === false) {
            // Nezapsaná cache stojí peníze při dalším běhu, ale běh sám
            // shodit nesmí.
            ErrorLogger::warn('TagCache: cache file not writable', ['path' => $this->path]);
        }
    }
}
