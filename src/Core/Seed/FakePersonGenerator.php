<?php

declare(strict_types=1);

namespace Shipard\Core\Seed;

class FakePersonGenerator
{
    private const FIRST_NAMES_MALE = [
        'Jan', 'Petr', 'Martin', 'Tomáš', 'Pavel', 'Jaroslav', 'Jiří',
        'Miroslav', 'Zdeněk', 'František', 'Václav', 'Karel', 'Josef',
        'David', 'Lukáš', 'Michal', 'Jakub', 'Adam', 'Ondřej', 'Marek',
        'Daniel', 'Filip', 'Vojtěch', 'Radek', 'Stanislav', 'Roman',
        'Aleš', 'Milan', 'Vladimír', 'Miloslav',
    ];

    private const FIRST_NAMES_FEMALE = [
        'Jana', 'Marie', 'Eva', 'Hana', 'Anna', 'Lenka', 'Kateřina',
        'Lucie', 'Věra', 'Alena', 'Petra', 'Veronika', 'Jaroslava',
        'Tereza', 'Martina', 'Michaela', 'Zuzana', 'Markéta', 'Barbora',
        'Monika', 'Ivana', 'Kristýna', 'Gabriela', 'Simona', 'Klára',
        'Dagmar', 'Jiřina', 'Renata', 'Nikola', 'Eliška',
    ];

    private const LAST_NAMES_MALE = [
        'Novák', 'Svoboda', 'Novotný', 'Dvořák', 'Černý', 'Procházka',
        'Kučera', 'Veselý', 'Horák', 'Němec', 'Marek', 'Pospíšil',
        'Pokorný', 'Hájek', 'Jelínek', 'Král', 'Růžička', 'Beneš',
        'Fiala', 'Sedláček', 'Doležal', 'Zeman', 'Kolář', 'Navrátil',
        'Čermák', 'Vaněk', 'Urban', 'Blaha', 'Šťastný', 'Kopecký',
    ];

    private const LAST_NAMES_FEMALE = [
        'Nováková', 'Svobodová', 'Novotná', 'Dvořáková', 'Černá', 'Procházková',
        'Kučerová', 'Veselá', 'Horáková', 'Němcová', 'Marková', 'Pospíšilová',
        'Pokorná', 'Hájková', 'Jelínková', 'Králová', 'Růžičková', 'Benešová',
        'Fialová', 'Sedláčková', 'Doležalová', 'Zemanová', 'Kolářová', 'Navrátilová',
        'Čermáková', 'Vaňková', 'Urbanová', 'Blahová', 'Šťastná', 'Kopecká',
    ];

    private const TITLES_BEFORE = [
        null, null, null, null, null, null, null, // ~70% bez titulu
        'Ing.', 'Mgr.', 'Bc.', 'MUDr.', 'JUDr.', 'PhDr.', 'RNDr.',
    ];

    private const TITLES_AFTER = [
        null, null, null, null, null, null, null, null, null, // ~90% bez titulu
        'Ph.D.', 'CSc.', 'MBA',
    ];

    private const COMPANY_PREFIXES = [
        'Alfa', 'Beta', 'Česká', 'Moravská', 'Pražská', 'Euro', 'Global',
        'Premium', 'First', 'Nova', 'Centrum', 'Progres', 'Techno', 'Digital',
        'Smart', 'Green', 'Solar', 'Rapid', 'Elite', 'Top',
    ];

    private const COMPANY_CORES = [
        'Stav', 'Tech', 'Logistik', 'Invest', 'Servis', 'Trading', 'Consulting',
        'Elektro', 'Auto', 'Gastro', 'Reality', 'Software', 'Media', 'Finance',
        'Transport', 'Agro', 'Design', 'Pharma', 'Security', 'Energy',
    ];

    private const COMPANY_SUFFIXES = [
        's.r.o.', 's.r.o.', 's.r.o.', 's.r.o.', // ~60% s.r.o.
        'a.s.', 'a.s.',                            // ~30% a.s.
        'SE',                                       // ~10% SE
    ];

    private const EMAIL_DOMAINS = [
        'example.com', 'example.cz', 'test.cz', 'firma.cz', 'mail.test',
        'demo.cz', 'sample.cz', 'testmail.cz',
    ];

    /** @return array<string, mixed> Data ready for INSERT into base_persons_persons */
    public function generate(int $index, int $personType): array
    {
        $personId = sprintf('TEST-%04d', $index);

        if ($personType === 2) {
            return $this->generateCompany($personId);
        }

        return $this->generateNaturalPerson($personId);
    }

    /** @return array<string, mixed> */
    private function generateNaturalPerson(string $personId): array
    {
        $isFemale = (bool) random_int(0, 1);
        $firstName = $isFemale
            ? self::pick(self::FIRST_NAMES_FEMALE)
            : self::pick(self::FIRST_NAMES_MALE);
        $lastName = $isFemale
            ? self::pick(self::LAST_NAMES_FEMALE)
            : self::pick(self::LAST_NAMES_MALE);

        $titleBefore = self::pick(self::TITLES_BEFORE);
        $titleAfter = self::pick(self::TITLES_AFTER);

        $fullName = trim($firstName . ' ' . $lastName);

        $emailLocal = self::toAscii(strtolower($firstName)) . '.' . self::toAscii(strtolower($lastName));
        $email = $emailLocal . '@' . self::pick(self::EMAIL_DOMAINS);

        return [
            'person_id'   => $personId,
            'person_type' => 1,
            'full_name'   => $fullName,
            'first_name'  => $firstName,
            'last_name'   => $lastName,
            'title_before' => $titleBefore,
            'title_after' => $titleAfter,
            'complex_name' => 0,
            'company_id'  => null,
            'tax_id'      => null,
            'vat_id'      => null,
            'email'       => $email,
            'phone'       => self::generatePhone(),
            'web'         => null,
            'birth_date'  => self::generateBirthDate(),
            'is_closed'   => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function generateCompany(string $personId): array
    {
        $prefix = self::pick(self::COMPANY_PREFIXES);
        $core = self::pick(self::COMPANY_CORES);
        $suffix = self::pick(self::COMPANY_SUFFIXES);

        $fullName = $prefix . ' ' . $core . ', ' . $suffix;
        $companyId = (string) random_int(10000000, 99999999);
        $taxId = 'CZ' . $companyId;

        $isVatPayer = (bool) random_int(0, 1);

        $emailLocal = self::toAscii(strtolower($prefix . $core));
        $email = 'info@' . $emailLocal . '.' . (random_int(0, 1) ? 'cz' : 'com');
        $web = 'https://www.' . $emailLocal . '.cz';

        return [
            'person_id'   => $personId,
            'person_type' => 2,
            'full_name'   => $fullName,
            'first_name'  => '',
            'last_name'   => $fullName,
            'title_before' => null,
            'title_after' => null,
            'complex_name' => 0,
            'company_id'  => $companyId,
            'tax_id'      => $taxId,
            'vat_id'      => $isVatPayer ? $taxId : null,
            'email'       => $email,
            'phone'       => self::generatePhone(),
            'web'         => $web,
            'birth_date'  => null,
            'is_closed'   => 0,
        ];
    }

    private static function generatePhone(): string
    {
        return '+420 ' . random_int(600, 799) . ' ' . sprintf('%03d', random_int(0, 999)) . ' ' . sprintf('%03d', random_int(0, 999));
    }

    private static function generateBirthDate(): string
    {
        $year = random_int(1955, 2002);
        $month = random_int(1, 12);
        $day = random_int(1, 28);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @template T
     *  @param array<T> $items
     *  @return T */
    private static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /** Simplified Czech diacritics removal for email generation */
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
