<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Porovnání dvou souborů pro EPO **po větách a atributech** (issue #55,
 * X9) — nástroj zlatého testu: vygenerované podání proti tomu, které bylo
 * doopravdy podáno.
 *
 * Textový diff by tu byl k ničemu: pořadí atributů, odsazení ani zápis
 * čísla (`210` vs. `210.00`) o shodě podání nic neříkají. Porovnává se
 * proto **hodnota atributu po normalizaci**:
 *
 * - čísla podle hodnoty (`210` = `210.00` = `+210`),
 * - datumy podle dne (`4.5.2026` = `04.05.2026`),
 * - ostatní jako trimnutý řetězec.
 *
 * **Nulový atribut je totéž co chybějící** (#55 F3-4): EPO bere
 * nevyplněnou hodnotu jako nulu, takže `odp_rezim="0"` a vynechaný
 * `odp_rezim` je totéž podání — starý Shipard nuly vypisoval u řádků, které
 * jeho výpočet znal, nový je vynechává. Nula se proto zahodí na obou
 * stranách ještě před porovnáním; věta, které tím nezůstane žádný atribut,
 * zmizí celá. Platí to i pro kódy s hodnotou `0` (`kod_rezim_pl`,
 * `k_pln_eu`) — jiná hodnota kódu rozdíl pořád udělá, jen chybějící kód
 * proti nulovému ne.
 *
 * Věty, kterých je víc (řádky hlášení), se párují **podle obsahu**, ne
 * podle pořadí: ze souboru se pro každou větu udělá multimnožina
 * normalizovaných řádků. Přeházené řádky téže sekce tedy rozdíl nedělají,
 * chybějící nebo přebývající ano.
 *
 * `ignoreAttributes` vynechá atributy, které se legitimně liší (jméno
 * a verze software, datum podání, kontaktní údaje, kdo sestavil).
 *
 * `foldAttributes` (cíl → zdroje) před porovnáním **sečte** číselné
 * atributy do cíle a zdroje zahodí, na obou stranách. Zlatý test tím
 * srovnává odpočet rozdělený na plný a krácený sloupec s podáním, které
 * měl celý v plném (#55 F3-6 (a)); hodnota, která má jen zdroj, se do cíle
 * přesune.
 */
final class EpoXmlDiff
{
    /**
     * Atributy, které se u zlatého testu neporovnávají (X9): identifikace
     * software, datum podání a kontakty, které se od podání mohly změnit.
     */
    public const DEFAULT_IGNORED = [
        'nazevSW', 'verzeSW', 'd_poddp',
        'sest_prijmeni', 'sest_jmeno', 'sest_telef',
        'c_telef', 'email',
    ];

    /**
     * @param list<string> $ignoreAttributes
     * @param array<string, list<string>> $foldAttributes cílový atribut → atributy,
     *        které se do něj před porovnáním přičtou (jen číselné)
     * @return list<array{sentence: string, kind: string, attribute?: string,
     *     expected?: string, actual?: string, row?: string}>
     *     prázdné pole = soubory se shodují
     */
    public static function compare(
        string $expected,
        string $actual,
        array $ignoreAttributes = self::DEFAULT_IGNORED,
        array $foldAttributes = [],
    ): array {
        $left  = self::sentences($expected, $ignoreAttributes, $foldAttributes);
        $right = self::sentences($actual, $ignoreAttributes, $foldAttributes);

        $differences = [];
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $sentence) {
            $expectedRows = $left[$sentence] ?? [];
            $actualRows   = $right[$sentence] ?? [];

            // Jedna věta (hlavička, souhrn): porovnává se atribut po
            // atributu, ať je vidět který se liší.
            if (count($expectedRows) === 1 && count($actualRows) === 1) {
                foreach (self::compareRow($sentence, $expectedRows[0], $actualRows[0]) as $difference) {
                    $differences[] = $difference;
                }
                continue;
            }

            foreach (self::compareRowSets($sentence, $expectedRows, $actualRows) as $difference) {
                $differences[] = $difference;
            }
        }

        usort($differences, static fn (array $a, array $b): int => [$a['sentence'], $a['kind'], $a['attribute'] ?? '']
            <=> [$b['sentence'], $b['kind'], $b['attribute'] ?? '']);

        return $differences;
    }

    /** Lidsky čitelný výpis rozdílů (CLI, hláška testu). */
    public static function format(array $differences): string
    {
        if ($differences === []) {
            return 'Soubory se shodují.';
        }

        $lines = [];
        foreach ($differences as $difference) {
            $lines[] = match ($difference['kind']) {
                'value'   => sprintf(
                    '%s/@%s: očekáváno „%s", vygenerováno „%s"',
                    $difference['sentence'],
                    $difference['attribute'],
                    $difference['expected'],
                    $difference['actual'],
                ),
                'missing' => sprintf('%s: chybí řádek %s', $difference['sentence'], $difference['row'] ?? ''),
                'extra'   => sprintf('%s: přebývá řádek %s', $difference['sentence'], $difference['row'] ?? ''),
                default   => sprintf('%s: %s', $difference['sentence'], $difference['kind']),
            };
        }
        return implode("\n", $lines);
    }

    // ── Rozbor ──────────────────────────────────────────────────────────────

    /**
     * Věty souboru → seznamy normalizovaných map atributů (bez ignorovaných,
     * po sloučení a bez nul).
     *
     * @param list<string> $ignoreAttributes
     * @param array<string, list<string>> $foldAttributes
     * @return array<string, list<array<string, string>>>
     */
    private static function sentences(string $xml, array $ignoreAttributes, array $foldAttributes): array
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml)) {
            throw new \InvalidArgumentException('Soubor není platné XML');
        }

        $out = [];
        foreach ((new \DOMXPath($dom))->query('//*[@*]') ?: [] as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $attributes = [];
            foreach ($element->attributes as $attribute) {
                if (in_array($attribute->name, $ignoreAttributes, true)) {
                    continue;
                }
                $attributes[$attribute->name] = self::normalize($attribute->value);
            }
            $attributes = self::fold($attributes, $foldAttributes);
            // Nula ≡ chybějící atribut — až po sloučení, aby se nula, která
            // vznikla součtem, zahodila taky.
            $attributes = array_filter($attributes, static fn (string $value): bool => $value !== '0');
            if ($attributes !== []) {
                ksort($attributes);
                $out[$element->nodeName][] = $attributes;
            }
        }
        return $out;
    }

    /**
     * Sečte zdrojové atributy do cílového a zdroje odstraní. Cíl vznikne
     * i tehdy, když ho věta neměla a měla jen zdroj (odpočet celý
     * v kráceném sloupci proti podání, které ho měl v plném).
     *
     * @param array<string, string> $attributes normalizované hodnoty
     * @param array<string, list<string>> $foldAttributes
     * @return array<string, string>
     */
    private static function fold(array $attributes, array $foldAttributes): array
    {
        foreach ($foldAttributes as $target => $sources) {
            $present = false;
            $sum     = 0.0;
            foreach ([(string) $target, ...$sources] as $attribute) {
                if (!array_key_exists($attribute, $attributes)) {
                    continue;
                }
                $present = true;
                $sum    += (float) $attributes[$attribute];
                unset($attributes[$attribute]);
            }
            if ($present) {
                $attributes[(string) $target] = self::number($sum);
            }
        }
        return $attributes;
    }

    /**
     * @param array<string, string> $expected
     * @param array<string, string> $actual
     * @return list<array<string, string>>
     */
    private static function compareRow(string $sentence, array $expected, array $actual): array
    {
        $differences = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $attribute) {
            if (($expected[$attribute] ?? null) === ($actual[$attribute] ?? null)) {
                continue;
            }
            $differences[] = [
                'sentence'  => $sentence,
                'kind'      => 'value',
                'attribute' => $attribute,
                'expected'  => $expected[$attribute] ?? '—',
                'actual'    => $actual[$attribute] ?? '—',
            ];
        }
        return $differences;
    }

    /**
     * Vícenásobné věty: spáruje se, co je shodné, a zbytek se vypíše jako
     * chybějící / přebývající řádky.
     *
     * @param list<array<string, string>> $expected
     * @param list<array<string, string>> $actual
     * @return list<array<string, string>>
     */
    private static function compareRowSets(string $sentence, array $expected, array $actual): array
    {
        $remaining = $actual;
        $missing   = [];

        foreach ($expected as $row) {
            $index = null;
            foreach ($remaining as $key => $candidate) {
                if ($candidate == $row) {
                    $index = $key;
                    break;
                }
            }
            if ($index === null) {
                $missing[] = $row;
                continue;
            }
            unset($remaining[$index]);
        }

        $differences = [];
        foreach ($missing as $row) {
            $differences[] = ['sentence' => $sentence, 'kind' => 'missing', 'row' => self::describe($row)];
        }
        foreach ($remaining as $row) {
            $differences[] = ['sentence' => $sentence, 'kind' => 'extra', 'row' => self::describe($row)];
        }
        return $differences;
    }

    /** @param array<string, string> $row */
    private static function describe(array $row): string
    {
        $parts = [];
        foreach ($row as $attribute => $value) {
            $parts[] = "{$attribute}=\"{$value}\"";
        }
        return implode(' ', $parts);
    }

    /**
     * Hodnota atributu ve tvaru, ve kterém má smysl ji porovnávat: číslo
     * podle hodnoty, datum podle dne, zbytek jako trimnutý text.
     */
    private static function normalize(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^[-+]?\d+(\.\d+)?$/', $value) === 1) {
            return self::number((float) $value);
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return $value;
    }

    /** Kanonický zápis čísla; nula (i záporná) je vždy `0`. */
    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        return $text === '' || $text === '-0' || $text === '-' ? '0' : $text;
    }
}
