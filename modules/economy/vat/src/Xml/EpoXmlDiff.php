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
 * Věty, kterých je víc (řádky hlášení), se párují **podle obsahu**, ne
 * podle pořadí: ze souboru se pro každou větu udělá multimnožina
 * normalizovaných řádků. Přeházené řádky téže sekce tedy rozdíl nedělají,
 * chybějící nebo přebývající ano.
 *
 * `ignoreAttributes` vynechá atributy, které se legitimně liší (jméno
 * a verze software, datum podání, kontaktní údaje, kdo sestavil).
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
     * @return list<array{sentence: string, kind: string, attribute?: string,
     *     expected?: string, actual?: string, row?: string}>
     *     prázdné pole = soubory se shodují
     */
    public static function compare(string $expected, string $actual, array $ignoreAttributes = self::DEFAULT_IGNORED): array
    {
        $left  = self::sentences($expected, $ignoreAttributes);
        $right = self::sentences($actual, $ignoreAttributes);

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
     * Věty souboru → seznamy normalizovaných map atributů.
     *
     * @param list<string> $ignoreAttributes
     * @return array<string, list<array<string, string>>>
     */
    private static function sentences(string $xml, array $ignoreAttributes): array
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
            if ($attributes !== []) {
                ksort($attributes);
                $out[$element->nodeName][] = $attributes;
            }
        }
        return $out;
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

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return $value;
    }
}
