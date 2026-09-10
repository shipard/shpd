<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

/**
 * Převody hodnoty strukturovaného pole mezi třemi podobami (#74):
 *
 *  - **JSON v DB** — string ve sloupci typu `json`, s klíčem `_schema`;
 *  - **pole** — to, co vidí `Document::beforeSave` a renderery;
 *  - **plochý formulář** — virtuální sloupce `<sloupec>.<pole>` (S4).
 *
 * Nic tady nevaliduje ani nekoercuje — to je práce
 * `StructuredFieldValidator`. Serializace je kanonická (`_schema`, pak pole
 * v pořadí schématu, pak zachované neznámé klíče), takže zápis formulářem
 * a zápis přes API dají nad stejnými hodnotami bajtově stejný JSON.
 */
final class StructuredFieldValues
{
    /**
     * Hodnota sloupce (string z DB / pole z API / null) jako pole.
     * Nedekódovatelný obsah = `null` (čtecí cesta radši ukáže prázdno, než
     * aby spadla). Zápisová cesta používá `decodeStrict()`.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(mixed $value): ?array
    {
        try {
            return self::decodeStrict($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Jako `decode()`, ale nedekódovatelný obsah je výjimka — zápis nesmí
     * tiše přepsat uloženou hodnotu prázdnem.
     *
     * @return array<string, mixed>|null
     * @throws \InvalidArgumentException
     */
    public static function decodeStrict(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Structured value must be a JSON object, array or null, got ' . get_debug_type($value),
            );
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'null') {
            return null;
        }
        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Structured value is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Structured value must decode to an object');
        }
        return $decoded;
    }

    /**
     * Hodnota → virtuální sloupce formuláře. Emituje **právě pole schématu**
     * (chybějící = null), tedy ani `_schema`, ani klíče, které schéma už
     * nezná: klient o nich nemá co vědět a zápisová cesta si je zachová sama
     * (merge nad uloženou hodnotou).
     *
     * @param array<string, mixed>|null $value
     * @return array<string, mixed> `<sloupec>.<pole>` => hodnota
     */
    public static function flatten(string $column, ?array $value, StructuredSchema $schema): array
    {
        $out = [];
        foreach ($schema->fields as $fieldId => $field) {
            $out[StructuredSchema::virtualColumn($column, $fieldId)] = $value[$fieldId] ?? null;
        }
        return $out;
    }

    /**
     * Virtuální sloupce z payloadu → hodnota, **slitá nad základem**.
     *
     * Sloučení, ne náhrada: klíč, který v payloadu není, si drží uloženou
     * hodnotu (`$base`). Tím projde i částečný zápis přes API
     * (`{"filing_profile.email": "…"}`) bez smazání zbytku profilu, zatímco
     * formulář posílá všechna pole schématu, takže vyprázdnění pole se
     * propíše. Neznámé klíče základu (pole zrušené novější verzí schématu)
     * zůstávají.
     *
     * @param array<string, mixed>      $data plochý payload
     * @param array<string, mixed>|null $base uložená (nebo poslaná) hodnota
     * @return array<string, mixed>
     */
    public static function unflatten(
        string $column,
        array $data,
        ?array $base,
        StructuredSchema $schema,
    ): array {
        $out = $base ?? [];
        unset($out[StructuredSchema::SCHEMA_KEY]);

        foreach (array_keys($schema->fields) as $fieldId) {
            $key = StructuredSchema::virtualColumn($column, $fieldId);
            if (array_key_exists($key, $data)) {
                $out[$fieldId] = $data[$key];
            }
        }
        return $out;
    }

    /** Nese payload alespoň jeden virtuální sloupec tohoto sloupce? */
    public static function hasVirtualKeys(string $column, array $data, StructuredSchema $schema): bool
    {
        foreach (array_keys($schema->fields) as $fieldId) {
            if (array_key_exists(StructuredSchema::virtualColumn($column, $fieldId), $data)) {
                return true;
            }
        }
        return false;
    }

    /** Odstraní virtuální sloupce daného sloupce z plochého pole. */
    public static function stripVirtualKeys(string $column, array $data, StructuredSchema $schema): array
    {
        foreach (array_keys($schema->fields) as $fieldId) {
            unset($data[StructuredSchema::virtualColumn($column, $fieldId)]);
        }
        return $data;
    }

    /**
     * Prázdná hodnota = žádné vyplněné pole. `false` a `0` se počítají jako
     * hodnota, `null` a `''` ne — proto nevyplněný profil skončí jako NULL
     * (a `required` se v něm nevynucuje, viz `StructuredFieldValidator`).
     *
     * @param array<string, mixed> $value
     */
    public static function isEmpty(array $value): bool
    {
        foreach ($value as $key => $item) {
            if ($key === StructuredSchema::SCHEMA_KEY) {
                continue;
            }
            if ($item === null) {
                continue;
            }
            if (is_string($item) && trim($item) === '') {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Hodnota → JSON pro DB. Kanonické pořadí: `_schema`, pole v pořadí
     * schématu (jen non-null), pak zachované neznámé klíče. Prázdná hodnota
     * → `null`, tedy NULL ve sloupci, ne `{}`.
     *
     * @param array<string, mixed> $value
     */
    public static function encode(array $value, StructuredSchema $schema): ?string
    {
        if (self::isEmpty($value)) {
            return null;
        }

        $ordered = [StructuredSchema::SCHEMA_KEY => $schema->key()];
        foreach (array_keys($schema->fields) as $fieldId) {
            if (array_key_exists($fieldId, $value) && $value[$fieldId] !== null) {
                $ordered[$fieldId] = $value[$fieldId];
            }
        }
        foreach ($value as $key => $item) {
            if ($key === StructuredSchema::SCHEMA_KEY || isset($schema->fields[$key]) || $item === null) {
                continue;
            }
            $ordered[$key] = $item;
        }

        return json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
