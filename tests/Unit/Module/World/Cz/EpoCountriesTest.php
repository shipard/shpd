<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Cz;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Číselník Země daňového portálu (`world.cz.epoCountries`, #55 F3-3) —
 * generovaný z exportu Finanční správy (`modules/world/cz/data/zeme.txt`,
 * `scripts/epo-countries.py`). Test hlídá to, na čem stojí XML podání:
 * klíče jsou ISO kódy známé v `world.base.countries` (profil podatele
 * ukládá právě ty), `epoName` je hodnota atributu `stat` a nesmí přesáhnout
 * 25 znaků (XSD `maxLength`).
 */
class EpoCountriesTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/world/cz';
    private const BASE_COUNTRIES = __DIR__ . '/../../../../../modules/world/base/config/countries.jsonc';

    /** XSD věty P: `stat` je `xs:string` s `maxLength="25"` (naz_zeme_c25). */
    private const EPO_NAME_MAX = 25;

    public function testIsRegisteredInModule(): void
    {
        $module = JsoncParser::parseFile(self::MODULE . '/module.jsonc');
        $files  = array_column($module['config'] ?? [], 'file', 'id');

        $this->assertSame('config/epoCountries.jsonc', $files['world.cz.epoCountries'] ?? null);
    }

    public function testEveryEntryHasNamesWithinPortalLimits(): void
    {
        $countries = $this->countries();
        $this->assertGreaterThan(200, count($countries), 'číselník má přes 200 platných zemí');

        foreach ($countries as $code => $entry) {
            $this->assertMatchesRegularExpression('/^[a-z]{2}$/', (string) $code, 'klíč je ISO alpha-2 malými písmeny');
            foreach (['name', 'name:en', 'epoName'] as $key) {
                $this->assertIsString($entry[$key] ?? null, "{$code}: chybí {$key}");
                $this->assertNotSame('', trim($entry[$key]), "{$code}: prázdné {$key}");
            }
            $this->assertLessThanOrEqual(
                self::EPO_NAME_MAX,
                mb_strlen($entry['epoName']),
                "{$code}: epoName „{$entry['epoName']}\" přesahuje 25 znaků schématu",
            );
        }
    }

    /**
     * Profil podatele vybírá stát z `world.base.countries` — každý kód, který
     * tam jde zvolit, musí mít u úřadu název, jinak podání skončí na chybě
     * `header.stat`. Obráceně to neplatí: číselník úřadu zná i území, která
     * v základním seznamu nejsou (Réunion, Tokelau, …), a to je v pořádku.
     */
    public function testEveryBaseCountryHasAPortalName(): void
    {
        $base = array_keys(JsoncParser::parseFile(self::BASE_COUNTRIES));

        $missing = array_diff($base, array_keys($this->countries()));
        $this->assertSame([], array_values($missing), 'země z world.base.countries bez názvu v číselníku úřadu');
    }

    public function testCzechRepublicUsesThePortalSpelling(): void
    {
        $cz = $this->countries()['cz'] ?? null;

        $this->assertNotNull($cz);
        $this->assertSame('ČESKÁ REPUBLIKA', $cz['epoName'], 'naz_zeme_c25 — ne „Česko" z world.base.countries');
        $this->assertSame('Česká republika', $cz['name']);
    }

    /** @return array<string, array<string, string>> */
    private function countries(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/config/epoCountries.jsonc');
    }
}
