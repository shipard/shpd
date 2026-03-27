<?php

declare(strict_types=1);

namespace Shipard\Core\Seed;

class FakeContactGenerator
{
    private const FIRST_NAMES = [
        'Jan', 'Petr', 'Martin', 'Eva', 'Jana', 'Lucie', 'Tomáš',
        'Kateřina', 'Pavel', 'Hana', 'David', 'Lenka', 'Jakub', 'Tereza',
    ];

    private const LAST_NAMES = [
        'Novák', 'Svobodová', 'Dvořák', 'Černá', 'Procházka', 'Veselá',
        'Kučera', 'Horáková', 'Němec', 'Marková', 'Pospíšil', 'Králová',
    ];

    private const ROLES = [
        'Jednatel', 'Ředitel', 'Účetní', 'Obchodní ředitel',
        'Asistentka', 'Vedoucí skladu', 'Technik', 'Obchodní zástupce',
        'Fakturant', 'Pokladní', 'Správce IT', null,
    ];

    private const EMAIL_DOMAINS = [
        'example.com', 'example.cz', 'test.cz', 'firma.cz', 'demo.cz',
    ];

    /**
     * Generate 1..maxCount contacts for a given person.
     *
     * @return list<array<string, mixed>> Arrays ready for INSERT into base_persons_contacts
     */
    public function generate(int $personId, int $maxCount = 3): array
    {
        $count = random_int(1, $maxCount);
        $contacts = [];

        for ($i = 0; $i < $count; $i++) {
            $firstName = self::pick(self::FIRST_NAMES);
            $lastName = self::pick(self::LAST_NAMES);
            $name = $firstName . ' ' . $lastName;

            $emailLocal = self::toAscii(strtolower($firstName)) . '.' . self::toAscii(strtolower($lastName));

            $contacts[] = [
                'person'    => $personId,
                'name'      => $name,
                'role'      => self::pick(self::ROLES),
                'email'     => $emailLocal . '@' . self::pick(self::EMAIL_DOMAINS),
                'phone'     => '+420 ' . random_int(600, 799) . ' ' . sprintf('%03d', random_int(0, 999)) . ' ' . sprintf('%03d', random_int(0, 999)),
                'note'      => null,
                'order_pos' => $i,
            ];
        }

        return $contacts;
    }

    /** @template T
     *  @param array<T> $items
     *  @return T */
    private static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    private static function toAscii(string $str): string
    {
        $map = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
            'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ];

        return strtr($str, $map);
    }
}
