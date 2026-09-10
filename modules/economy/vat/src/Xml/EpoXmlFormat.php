<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Formátování hodnot do atributů XML pro EPO podle popisu struktury
 * Finanční správy (issue #55, X1). Sdílené writery i resolverem hlavičky,
 * aby datum a číslo vypadalo všude stejně.
 *
 * Pravidla:
 * - datum `DD.MM.RRRR` (typ `dateInMultiFormat` ve schématu),
 * - číslo bez oddělovače tisíců, desetinná tečka, pevný počet míst dle
 *   písemnosti (přiznání a souhrnné hlášení celé Kč, kontrolní haléře),
 * - `null` = atribut se nevypíše; nula se vynechává, pokud volající
 *   nežádá jinak (součtové řádky),
 * - záporná hodnota je legitimní (dodatečné přiznání vykazuje rozdíly).
 */
final class EpoXmlFormat
{
    /** Peněžní hodnota; `null` = atribut vynechat. */
    public static function money(float $value, int $scale, bool $keepZero = false): ?string
    {
        $value = round($value, $scale);
        if (!$keepZero && abs($value) < (10 ** -$scale) / 2) {
            return null;
        }
        // -0 by prošlo XSD, ale v podání nedává smysl.
        if ($value == 0.0) {
            $value = 0.0;
        }
        return number_format($value, $scale, '.', '');
    }

    /** Celé číslo (počet plnění, kód); nula se vynechává. */
    public static function count(?int $value): ?string
    {
        return $value === null || $value === 0 ? null : (string) $value;
    }

    /** Datum `DD.MM.RRRR` (s nulami, jak ho popisuje struktura). */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y');
        }
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) === 1) {
            return sprintf('%02d.%02d.%s', (int) $m[3], (int) $m[2], $m[1]);
        }
        return $text;
    }

    /** Vypadá hodnota jako ISO datum? (hlavička nese datumy jako `Y-m-d`) */
    public static function isIsoDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /** Ano/Ne jako `A` / `N` (atributy `trans`, `pomer`, `zdph_44`). */
    public static function yesNo(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'A' : 'N';
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0 ? 'A' : 'N';
        }
        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'ano', 'a', 'on'], true) ? 'A' : 'N';
    }

    /** DIČ pro atribut typu `[0-9]{1,10}` — číselná část bez kódu státu. */
    public static function taxNumberDigits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        return $digits === '' ? null : $digits;
    }

    /**
     * Rozpad DIČ registrace v jiném členském státě na kód státu a číslo
     * (souhrnné hlášení `k_stat` + `c_vat`, kontrolní hlášení A.2).
     * Bez rozpoznaného prefixu je kód státu `null` a celá hodnota jde do
     * čísla — validace to zachytí líp než tichý odhad.
     *
     * @return array{0: ?string, 1: ?string} [kód státu, číslo bez prefixu]
     */
    public static function euVatId(mixed $value): array
    {
        $text = strtoupper(preg_replace('/[\s.,\-\/]+/', '', (string) ($value ?? '')) ?? '');
        if ($text === '') {
            return [null, null];
        }
        if (preg_match('/^([A-Z]{2})(.+)$/', $text, $m) === 1) {
            return [$m[1], $m[2]];
        }
        return [null, $text];
    }

    /**
     * Text rozlámaný na řádky dané délky pro textovou přílohu (věta R).
     * Láme se na slovech, delší slovo se rozdělí natvrdo; prázdné řádky
     * se zahazují.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $length): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }
            foreach (explode("\n", wordwrap($paragraph, $length, "\n", true)) as $line) {
                $line = rtrim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }
}
