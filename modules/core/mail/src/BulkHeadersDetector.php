<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Deterministická detekce hromadné pošty z hlaviček raw `.eml`
 * (Fáze 3 Spisovny, design §8). Čte výhradně hlavičkový blok
 * (po první prázdný řádek) — hlavička v těle zprávy nesmí matchnout.
 *
 * Signál je true, když platí aspoň jedno:
 *   - `List-Unsubscribe` je přítomna
 *   - `Precedence` je `bulk` nebo `list`
 *   - `Auto-Submitted` je přítomna s hodnotou jinou než `no`
 *   - `List-Id` je přítomna
 *
 * Žádné heuristiky nad tělem. Výsledek je jen signál (zásada D7) —
 * sám o sobě nikdy neauto-archivuje.
 */
class BulkHeadersDetector
{
    public function detect(string $rawEml): bool
    {
        $headers = $this->parseHeaderBlock($rawEml);

        if (isset($headers['list-unsubscribe']) || isset($headers['list-id'])) {
            return true;
        }

        $precedence = strtolower(trim($headers['precedence'] ?? ''));
        if ($precedence === 'bulk' || $precedence === 'list') {
            return true;
        }

        if (isset($headers['auto-submitted'])) {
            $autoSubmitted = strtolower(trim($headers['auto-submitted']));
            // hodnota může nést komentář/parametry, např. "auto-generated (rule)"
            if ($autoSubmitted !== 'no' && !str_starts_with($autoSubmitted, 'no ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rozparsuje hlavičkový blok na mapu lowercase název → hodnota
     * (první výskyt vyhrává; víc nepotřebujeme). Unfolduje pokračovací
     * řádky (RFC 5322 folding — řádek začínající WSP), toleruje CRLF i LF.
     *
     * @return array<string, string>
     */
    private function parseHeaderBlock(string $rawEml): array
    {
        // Hlavičkový blok končí první prázdnou řádkou.
        $blockEnd = preg_match('/\r?\n\r?\n/', $rawEml, $m, PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1]
            : strlen($rawEml);
        $block = substr($rawEml, 0, $blockEnd);

        // Unfold: CRLF/LF následované mezerou či tabem = pokračování hodnoty.
        $block = preg_replace('/\r?\n[ \t]+/', ' ', $block) ?? $block;

        $headers = [];
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            if ($name === '' || isset($headers[$name])) {
                continue;
            }
            $headers[$name] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }
}
