<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

/**
 * Sdílené formátování buněk sub-tabulek (docs/edit-forms.md kap. 15).
 *
 * Používá ho default renderer v `TableForm::renderSubtable()` i per-form
 * overridy (`DocsHeadsFormBase`, `PersonsForm`), aby částky, čísla a data
 * vypadaly ve všech sub-tabulkách stejně. Formát čísel je záměrně shodný
 * s privátními `formatMoney()` ve viewerech (čárka, mezera po tisících);
 * jejich sjednocení sem je mimo rozsah (tasks/TODO.md).
 *
 * Konvence: `null` / `''` na vstupu → `null` (prázdná buňka), nikdy „0,00".
 */
final class SubtableCellFormatter
{
    private const DECIMAL_POINT = ',';
    private const THOUSANDS_SEP = ' ';

    /** Částka s pevným počtem desetinných míst (default 2). */
    public static function money(mixed $amount, int $decimals = 2): ?string
    {
        return self::number($amount, $decimals);
    }

    /** Číslo s pevným počtem desetinných míst. */
    public static function number(mixed $value, int $decimals): ?string
    {
        if (!self::isPresent($value)) {
            return null;
        }
        return number_format((float) $value, max(0, $decimals), self::DECIMAL_POINT, self::THOUSANDS_SEP);
    }

    /**
     * Číslo s ořezanými koncovými nulami v desetinné části
     * (množství „10,0000" → „10", sazba „21,00" → „21", „2,5000" → „2,5").
     */
    public static function trimmedNumber(mixed $value, int $decimals): ?string
    {
        $formatted = self::number($value, $decimals);
        if ($formatted === null) {
            return null;
        }
        if (str_contains($formatted, self::DECIMAL_POINT)) {
            $formatted = rtrim(rtrim($formatted, '0'), self::DECIMAL_POINT);
        }
        return $formatted;
    }

    /**
     * Jednotková cena: až `$maxDecimals` míst, koncové nuly ořezané, ale
     * nejméně `$minDecimals` míst („12,3456" zůstane, „1 000,0000" → „1 000,00").
     */
    public static function price(mixed $value, int $maxDecimals = 4, int $minDecimals = 2): ?string
    {
        if (!self::isPresent($value)) {
            return null;
        }
        $maxDecimals = max(0, $maxDecimals);
        $minDecimals = max(0, min($minDecimals, $maxDecimals));
        $formatted = self::number($value, $maxDecimals);
        if ($formatted === null || $maxDecimals === 0) {
            return $formatted;
        }
        [$int, $frac] = explode(self::DECIMAL_POINT, $formatted, 2) + [1 => ''];
        $frac = rtrim($frac, '0');
        if (strlen($frac) < $minDecimals) {
            $frac = str_pad($frac, $minDecimals, '0');
        }
        return $frac === '' ? $int : $int . self::DECIMAL_POINT . $frac;
    }

    /** Datum `Y-m-d` (nebo DateTimeInterface) → `d.m.Y`; neparsovatelný vstup se vrátí beze změny. */
    public static function date(mixed $value): ?string
    {
        $dt = self::toDateTime($value);
        if ($dt === null) {
            return self::isPresent($value) ? (string) $value : null;
        }
        return $dt->format('d.m.Y');
    }

    /** Datum a čas → `d.m.Y H:i`; neparsovatelný vstup se vrátí beze změny. */
    public static function dateTime(mixed $value): ?string
    {
        $dt = self::toDateTime($value);
        if ($dt === null) {
            return self::isPresent($value) ? (string) $value : null;
        }
        return $dt->format('d.m.Y H:i');
    }

    /**
     * Boolean → lokalizované „Ano" / „Ne". Labely dodává volající
     * (typicky `TableForm::booleanLabels()` z cfgItem `core.system.formDefaults`).
     */
    public static function boolean(mixed $value, string $yes, string $no): string
    {
        $truthy = $value === true
            || (is_numeric($value) && (float) $value !== 0.0)
            || (is_string($value) && strtolower($value) === 'true');
        return $truthy ? $yes : $no;
    }

    private static function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function toDateTime(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
