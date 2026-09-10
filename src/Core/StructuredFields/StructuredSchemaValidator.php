<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

use Shipard\Core\Form\FormElement;

/**
 * Kontrola formátu schématu strukturovaného pole (I1 v #74) nad **surovým**
 * cfgItem — tedy před lokalizací, aby se `name:cs` kontrolovalo jako
 * vícejazyčná varianta a ne jako neznámý klíč.
 *
 * Běží v `ConfigCompiler` pro každý cfgItem, na který ukazuje atribut
 * `schema` nějakého sloupce; neznámý klíč, neznámý typ nebo chybějící
 * povinný atribut zastaví `ds-upgrade` s cestou k chybě. Důvod je stejný
 * jako u definic tabulek: překlep v `requred` nebo `enumSting` nesmí tiše
 * vypnout validaci hodnot.
 */
final class StructuredSchemaValidator
{
    /**
     * `inputType` povolené ve schématu — podmnožina `FormElement`.
     * `password` je vyloučené záměrně: strukturované pole se ukládá do
     * `json` sloupce v plaintextu, takže do něj citlivé údaje nepatří
     * (na ty je typ `encrypted_text`, viz docs/operations/secrets.md).
     */
    private const FORBIDDEN_INPUT_TYPES = ['password'];

    private const ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Verze jde do `_schema` za lomítko — lomítko a mezery v ní být nesmí. */
    private const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * @param array<string, mixed> $raw Surový obsah cfgItem (JsoncParser).
     * @throws \RuntimeException s cestou k chybnému místu
     */
    public static function validate(string $cfgItem, array $raw): void
    {
        self::assertKnownKeys($cfgItem, '', $raw, StructuredSchema::ALLOWED_KEYS, []);

        $version = $raw['version'] ?? null;
        if (!is_string($version) && !is_int($version)) {
            self::fail($cfgItem, 'version', 'must be a string (e.g. "2026")');
        }
        if (!preg_match(self::VERSION_PATTERN, (string) $version)) {
            self::fail($cfgItem, 'version', "invalid value '" . (string) $version . "' (allowed: letters, digits, . _ -)");
        }

        $groupIds = self::validateGroups($cfgItem, $raw['groups'] ?? []);
        self::validateFields($cfgItem, $raw['fields'] ?? null, $groupIds);
    }

    /**
     * @param mixed $groups
     * @return list<string> deklarovaná id skupin
     */
    private static function validateGroups(string $cfgItem, mixed $groups): array
    {
        if ($groups === []) {
            return [];
        }
        if (!is_array($groups) || !array_is_list($groups)) {
            self::fail($cfgItem, 'groups', 'must be a list of objects');
        }

        $ids = [];
        foreach ($groups as $i => $group) {
            $path = "groups[{$i}]";
            if (!is_array($group)) {
                self::fail($cfgItem, $path, 'must be an object');
            }
            self::assertKnownKeys(
                $cfgItem,
                $path,
                $group,
                StructuredSchema::ALLOWED_GROUP_KEYS,
                ['name'],
            );
            $id = $group['id'] ?? null;
            if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
                self::fail($cfgItem, "{$path}.id", 'must match [a-z][a-z0-9_]*');
            }
            if (in_array($id, $ids, true)) {
                self::fail($cfgItem, "{$path}.id", "duplicate group id '{$id}'");
            }
            if (!isset($group['name']) || !is_string($group['name']) || $group['name'] === '') {
                // Holé `name` je povinný fallback i18n (docs/modules.md).
                self::fail($cfgItem, "{$path}.name", 'missing bare `name` (fallback for :lang variants)');
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /** @param list<string> $groupIds */
    private static function validateFields(string $cfgItem, mixed $fields, array $groupIds): void
    {
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            self::fail($cfgItem, 'fields', 'must be a non-empty list of objects');
        }

        $ids = [];
        foreach ($fields as $i => $field) {
            $path = "fields[{$i}]";
            if (!is_array($field)) {
                self::fail($cfgItem, $path, 'must be an object');
            }
            self::assertKnownKeys(
                $cfgItem,
                $path,
                $field,
                StructuredField::ALLOWED_KEYS,
                StructuredField::LOCALIZED_KEYS,
            );

            $id = $field['id'] ?? null;
            if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
                self::fail($cfgItem, "{$path}.id", 'must match [a-z][a-z0-9_]* (no dots — see StructuredSchema::PATH_SEPARATOR)');
            }
            if (in_array($id, $ids, true)) {
                self::fail($cfgItem, "{$path}.id", "duplicate field id '{$id}'");
            }
            $ids[] = $id;

            $type = $field['type'] ?? null;
            if (!is_string($type) || !in_array($type, StructuredField::ALLOWED_TYPES, true)) {
                self::fail($cfgItem, "{$path}.type", sprintf(
                    "invalid type '%s'. Allowed: %s",
                    is_scalar($type) ? (string) $type : get_debug_type($type),
                    implode(', ', StructuredField::ALLOWED_TYPES),
                ));
            }

            if (!isset($field['name']) || !is_string($field['name']) || $field['name'] === '') {
                self::fail($cfgItem, "{$path}.name", 'missing bare `name` (fallback for :lang variants)');
            }

            if (isset($field['group'])) {
                if (!is_string($field['group']) || !in_array($field['group'], $groupIds, true)) {
                    self::fail($cfgItem, "{$path}.group", sprintf(
                        "unknown group '%s'. Declared: %s",
                        is_scalar($field['group']) ? (string) $field['group'] : get_debug_type($field['group']),
                        $groupIds === [] ? '(none)' : implode(', ', $groupIds),
                    ));
                }
            }

            self::validateTypeAttributes($cfgItem, $path, $type, $field);
            self::validateFlags($cfgItem, $path, $field);
        }
    }

    /** @param array<string, mixed> $field */
    private static function validateTypeAttributes(string $cfgItem, string $path, string $type, array $field): void
    {
        $needsLength = in_array($type, StructuredField::LENGTH_TYPES, true);
        if ($needsLength) {
            if (!isset($field['length']) || !is_int($field['length']) || $field['length'] <= 0) {
                self::fail($cfgItem, "{$path}.length", "type '{$type}' requires a positive int 'length'");
            }
        } elseif (array_key_exists('length', $field)) {
            self::fail($cfgItem, "{$path}.length", "type '{$type}' does not take 'length'");
        }

        if ($type === 'numeric') {
            if (!isset($field['precision']) || !is_int($field['precision']) || $field['precision'] <= 0) {
                self::fail($cfgItem, "{$path}.precision", "type 'numeric' requires a positive int 'precision'");
            }
            if (!isset($field['scale']) || !is_int($field['scale']) || $field['scale'] < 0) {
                self::fail($cfgItem, "{$path}.scale", "type 'numeric' requires a non-negative int 'scale'");
            }
            if ($field['scale'] > $field['precision']) {
                self::fail($cfgItem, "{$path}.scale", 'scale must not exceed precision');
            }
        } else {
            foreach (['precision', 'scale'] as $key) {
                if (array_key_exists($key, $field)) {
                    self::fail($cfgItem, "{$path}.{$key}", "type '{$type}' does not take '{$key}'");
                }
            }
        }

        if (in_array($type, StructuredField::ENUM_TYPES, true)) {
            if (!isset($field['cfgItem']) || !is_string($field['cfgItem']) || $field['cfgItem'] === '') {
                self::fail($cfgItem, "{$path}.cfgItem", "type '{$type}' requires 'cfgItem'");
            }
        } elseif (array_key_exists('cfgItem', $field)) {
            // cfgItem = zdroj hodnot enumu; na jiném typu by tichá dekorace
            // slibovala číselník, který se nikde nevynutí.
            self::fail($cfgItem, "{$path}.cfgItem", "type '{$type}' does not take 'cfgItem' (use enumInt / enumString)");
        }
    }

    /** @param array<string, mixed> $field */
    private static function validateFlags(string $cfgItem, string $path, array $field): void
    {
        foreach (['required', 'readOnly'] as $key) {
            if (array_key_exists($key, $field) && !is_bool($field[$key])) {
                self::fail($cfgItem, "{$path}.{$key}", 'must be a bool');
            }
        }

        if (array_key_exists('default', $field)
            && $field['default'] !== null
            && !is_scalar($field['default'])
        ) {
            self::fail($cfgItem, "{$path}.default", 'must be a scalar or null');
        }

        if (array_key_exists('inputType', $field)) {
            $inputType = $field['inputType'];
            if (!is_string($inputType)
                || !in_array($inputType, FormElement::ALLOWED_INPUT_TYPES, true)
                || in_array($inputType, self::FORBIDDEN_INPUT_TYPES, true)
            ) {
                $allowed = array_values(array_filter(
                    FormElement::ALLOWED_INPUT_TYPES,
                    static fn(?string $t): bool => $t !== null && !in_array($t, self::FORBIDDEN_INPUT_TYPES, true),
                ));
                self::fail($cfgItem, "{$path}.inputType", sprintf(
                    "invalid inputType '%s'. Allowed: %s",
                    is_scalar($inputType) ? (string) $inputType : get_debug_type($inputType),
                    implode(', ', $allowed),
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     * @param list<string>         $localized Klíče, které smí mít `:lang` varianty
     */
    private static function assertKnownKeys(
        string $cfgItem,
        string $path,
        array $data,
        array $allowed,
        array $localized,
    ): void {
        foreach (array_keys($data) as $key) {
            $key = (string) $key;
            $bare = $key;
            $colon = strpos($key, ':');
            if ($colon !== false) {
                $bare = substr($key, 0, $colon);
                if (!in_array($bare, $localized, true)) {
                    self::fail($cfgItem, self::join($path, $key), sprintf(
                        "key '%s' has no localized variants. Localizable: %s",
                        $bare,
                        $localized === [] ? '(none)' : implode(', ', $localized),
                    ));
                }
            }
            if (!in_array($bare, $allowed, true)) {
                self::fail($cfgItem, self::join($path, $key), sprintf(
                    'unknown key. Allowed: %s',
                    implode(', ', $allowed),
                ));
            }
        }
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }

    private static function fail(string $cfgItem, string $path, string $message): never
    {
        throw new \RuntimeException("Structured schema '{$cfgItem}' → {$path}: {$message}");
    }
}
