<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\ValidationError;

/**
 * Validace a normalizace hodnot strukturovaného pole (I4 v #74).
 *
 * Chyby jsou **field-level** s `column = "<sloupec>.<pole>"` — kontrakt
 * `field` (docs/edit-forms.md §8) je stejný jako u běžných sloupců, takže
 * klient chybu přiřadí k inputu sám.
 *
 * Žádná „chytrá" koerce: trim, číselný parse a rozpoznání boolean
 * reprezentací. Co neprojde, je chyba — mlčky opravená hodnota je horší než
 * odmítnutý zápis, protože se dostane do XML podání.
 *
 * `required` se vynucuje **jen v neprázdné hodnotě**: dokud uživatel do
 * profilu nic nezadal, sloupec je NULL a povinná pole nikoho neblokují
 * (jinak by nešlo uložit registraci DPH bez vyplněných podacích údajů).
 */
final class StructuredFieldValidator
{
    private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';

    private const TRUE_STRINGS = ['1', 'true', 'yes', 'on'];
    private const FALSE_STRINGS = ['0', 'false', 'no', 'off', ''];

    /**
     * @param array<string, mixed> $value nevalidovaná hodnota (po unflatten)
     * @return array{value: array<string, mixed>, errors: list<ValidationError>}
     *         `value` je normalizovaná hodnota; při chybách se nepoužívá
     */
    public static function validate(
        string $column,
        array $value,
        StructuredSchema $schema,
        ?ConfigRuntime $config,
    ): array {
        if (StructuredFieldValues::isEmpty($value)) {
            return ['value' => [], 'errors' => []];
        }

        $out = $value;
        $errors = [];

        foreach ($schema->fields as $fieldId => $field) {
            $raw = $value[$fieldId] ?? null;
            $error = null;
            $normalized = self::normalize($field, $raw, $config, $error);

            if ($error !== null) {
                $errors[] = new ValidationError(
                    StructuredSchema::virtualColumn($column, $fieldId),
                    $error['message'],
                    $error['code'],
                );
                continue;
            }

            if ($normalized === null && $field->required) {
                $errors[] = new ValidationError(
                    StructuredSchema::virtualColumn($column, $fieldId),
                    'Pole je povinné',
                    'required',
                );
                continue;
            }

            $out[$fieldId] = $normalized;
        }

        return ['value' => $out, 'errors' => $errors];
    }

    /**
     * @param array{message: string, code: string}|null $error out-param
     */
    private static function normalize(
        StructuredField $field,
        mixed $raw,
        ?ConfigRuntime $config,
        ?array &$error,
    ): mixed {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            // Tabulky řádků uvnitř pole jsou fáze 2 (S6) — dokud nejsou,
            // je pole v hodnotě chyba, ne něco, co se zkusí uložit.
            $error = ['message' => 'Hodnota nesmí být seznam', 'code' => 'invalid_value'];
            return null;
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '' && $field->type !== 'boolean') {
                return null;
            }
        }

        return match ($field->type) {
            'varchar', 'text'      => self::normalizeString($field, $raw, $error),
            'int'                  => self::normalizeInt($raw, $error),
            'numeric'              => self::normalizeNumeric($field, $raw, $error),
            'date'                 => self::normalizeDate($raw, $error),
            'boolean'              => self::normalizeBool($raw, $error),
            'enumInt', 'enumString' => self::normalizeEnum($field, $raw, $config, $error),
            default                => null,
        };
    }

    private static function normalizeString(StructuredField $field, mixed $raw, ?array &$error): ?string
    {
        if (is_bool($raw) || !is_scalar($raw)) {
            $error = ['message' => 'Zadejte text', 'code' => 'invalid_value'];
            return null;
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return null;
        }
        if ($field->length !== null && mb_strlen($text) > $field->length) {
            $error = [
                'message' => sprintf('Nejvýše %d znaků (zadáno %d)', $field->length, mb_strlen($text)),
                'code'    => 'too_long',
            ];
            return null;
        }
        return $text;
    }

    private static function normalizeInt(mixed $raw, ?array &$error): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        $error = ['message' => 'Zadejte celé číslo', 'code' => 'invalid_int'];
        return null;
    }

    /**
     * Numeric se ukládá jako **string** v kanonickém tvaru — stejně jako
     * `numeric` sloupce vrací dibi. Žádné zaokrouhlování: víc desetinných
     * míst, než dovoluje `scale`, je chyba.
     */
    private static function normalizeNumeric(StructuredField $field, mixed $raw, ?array &$error): ?string
    {
        if (is_bool($raw) || !is_scalar($raw)) {
            $error = ['message' => 'Zadejte číslo', 'code' => 'invalid_number'];
            return null;
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return null;
        }

        $precision = $field->precision ?? 0;
        $scale = $field->scale ?? 0;
        $intDigits = max(1, $precision - $scale);
        $pattern = $scale > 0
            ? sprintf('/^-?\d{1,%d}(\.\d{1,%d})?$/', $intDigits, $scale)
            : sprintf('/^-?\d{1,%d}$/', $intDigits);

        if (preg_match($pattern, $text) !== 1) {
            $error = [
                'message' => $scale > 0
                    ? sprintf('Zadejte číslo (nejvýše %d desetinných míst)', $scale)
                    : 'Zadejte celé číslo',
                'code' => 'invalid_number',
            ];
            return null;
        }
        return $text;
    }

    private static function normalizeDate(mixed $raw, ?array &$error): ?string
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        if (is_string($raw) && preg_match(self::DATE_PATTERN, $raw, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return $raw;
        }
        $error = ['message' => 'Zadejte datum ve formátu RRRR-MM-DD', 'code' => 'invalid_date'];
        return null;
    }

    private static function normalizeBool(mixed $raw, ?array &$error): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw === 1;
        }
        if (is_string($raw)) {
            $lower = strtolower(trim($raw));
            if ($lower === '') {
                return null;
            }
            if (in_array($lower, self::TRUE_STRINGS, true)) {
                return true;
            }
            if (in_array($lower, self::FALSE_STRINGS, true)) {
                return false;
            }
        }
        $error = ['message' => 'Zadejte Ano nebo Ne', 'code' => 'invalid_bool'];
        return null;
    }

    private static function normalizeEnum(
        StructuredField $field,
        mixed $raw,
        ?ConfigRuntime $config,
        ?array &$error,
    ): int|string|null {
        if ($field->type === 'enumInt') {
            $value = self::normalizeInt($raw, $error);
            if ($error !== null || $value === null) {
                return null;
            }
            $key = (string) $value;
        } else {
            $value = self::normalizeString($field, $raw, $error);
            if ($error !== null || $value === null) {
                return null;
            }
            $key = $value;
        }

        // Chybějící číselník neumíme zkontrolovat (nezkompilovaná konfigurace);
        // existenci cfgItem schématu hlídá ConfigCompiler při ds-upgrade.
        $cfgData = $field->cfgItem !== null ? $config?->cfgItem($field->cfgItem) : null;
        if (is_array($cfgData) && !array_key_exists($key, $cfgData)) {
            $error = ['message' => 'Hodnota není v číselníku', 'code' => 'invalid_enum'];
            return null;
        }

        return $value;
    }
}
