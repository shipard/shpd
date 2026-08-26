<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Převod DB hodnot (Dibi řádky) na deterministické canonical hodnoty.
 *
 * Datumy jako `Y-m-d`, časy jako `Y-m-d\TH:i:s` (lokální čas DS, bez
 * posunu — `DocumentApplier::mapExtractedAt` je parsuje beze změny),
 * čísla jako float/int, prázdné řetězce jako null. `prune()` odstraní
 * null a prázdná pole rekurzivně, aby soubor sady nesl jen skutečný obsah.
 */
final class ValueNormalizer
{
    public static function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return self::dateTime($value);
        }
        if (!is_scalar($value)) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            $s = trim($value);
            return $s === '' ? null : substr($s, 0, 10);
        }
        return null;
    }

    public static function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }
        if (is_string($value)) {
            $s = trim($value);
            if ($s === '') {
                return null;
            }
            try {
                return (new \DateTimeImmutable($s))->format('Y-m-d\TH:i:s');
            } catch (\Exception) {
                return null;
            }
        }
        return null;
    }

    public static function float(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    public static function int(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (bool) $value;
    }

    /** Měna v canonicalu je ISO 4217 velkými písmeny. */
    public static function currencyUpper(mixed $value): ?string
    {
        $s = self::str($value);
        return $s === null ? null : strtoupper($s);
    }

    /** Země v canonicalu osob je ISO 3166-1 alpha-2 malými písmeny. */
    public static function countryLower(mixed $value): ?string
    {
        $s = self::str($value);
        return $s === null ? null : strtolower($s);
    }

    /**
     * @return array<mixed>|null
     */
    public static function json(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Rekurzivně odstraní null hodnoty a prázdná pole. Pořadí klíčů
     * zůstává. Číselně indexovaná pole se přeindexují.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function prune(array $data): array
    {
        $out = [];
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::prune($value);
                if ($value === []) {
                    continue;
                }
            }
            if ($value === null) {
                continue;
            }
            if ($isList) {
                $out[] = $value;
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
