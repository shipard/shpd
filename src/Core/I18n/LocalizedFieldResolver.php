<?php

declare(strict_types=1);

namespace Shipard\Core\I18n;

class LocalizedFieldResolver
{
    public static function resolve(array $data, string $field, string $language): string
    {
        $langKey = "{$field}:{$language}";
        if (isset($data[$langKey]) && is_string($data[$langKey]) && $data[$langKey] !== '') {
            return $data[$langKey];
        }

        $enKey = "{$field}:en";
        if (isset($data[$enKey]) && is_string($data[$enKey]) && $data[$enKey] !== '') {
            return $data[$enKey];
        }

        if (isset($data[$field]) && is_string($data[$field])) {
            return $data[$field];
        }

        return '';
    }
}
