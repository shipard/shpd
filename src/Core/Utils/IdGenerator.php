<?php

declare(strict_types=1);

namespace Shipard\Core\Utils;

class IdGenerator
{
    private const CHARSET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const GROUP_LENGTH = 4;
    private const GROUP_COUNT = 4;

    public function generate(string $dataSourcesDir): string
    {
        do {
            $id = $this->generateRandom();
        } while (is_dir($dataSourcesDir . '/' . $id));

        return $id;
    }

    private function generateRandom(): string
    {
        $charsetLength = strlen(self::CHARSET);
        $groups = [];

        for ($g = 0; $g < self::GROUP_COUNT; $g++) {
            $group = '';
            for ($i = 0; $i < self::GROUP_LENGTH; $i++) {
                $group .= self::CHARSET[random_int(0, $charsetLength - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }

    public static function toDatabaseName(string $id): string
    {
        return str_replace('-', '_', $id);
    }

    public static function toDatabaseUser(string $id): string
    {
        $parts = explode('-', $id);
        return 'shpd_' . $parts[0] . $parts[1];
    }
}
