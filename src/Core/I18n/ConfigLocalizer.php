<?php

declare(strict_types=1);

namespace Shipard\Core\I18n;

class ConfigLocalizer
{
    public static function localize(array $data, string $language): array
    {
        // Collect bare field names that have at least one :lang variant
        $hasVariants = [];
        foreach (array_keys($data) as $key) {
            if (str_contains((string) $key, ':')) {
                $base = substr((string) $key, 0, strpos((string) $key, ':'));
                $hasVariants[$base] = true;
            }
        }

        $output = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;

            // Skip language variant keys
            if (str_contains($key, ':')) {
                continue;
            }

            if (isset($hasVariants[$key])) {
                // Resolve via fallback chain
                $output[$key] = LocalizedFieldResolver::resolve($data, $key, $language);
            } elseif (is_array($value)) {
                $output[$key] = self::localize($value, $language);
            } else {
                $output[$key] = $value;
            }
        }

        return $output;
    }
}
