<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\SchemaLoader;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\StructuredFields\StructuredField;
use Shipard\Core\StructuredFields\StructuredSchema;
use Shipard\Core\StructuredFields\StructuredSchemaValidator;
use Shipard\Core\Utils\JsoncParser;

/**
 * Dodané schéma profilu podatele (#74, #55 D19) musí projít validátorem
 * a všechny číselníky, na které ukazuje, musí existovat v modulech — jinak
 * by select v Podacích údajích zůstal prázdný a každá hodnota by spadla na
 * `invalid_enum`. `ConfigCompiler` hlídá jen cfgItem samotného schématu,
 * enumy uvnitř ne.
 */
class FilingProfileSchemaTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const SCHEMA_FILE = self::MODULES . '/economy/vat/config/filingProfileCz.jsonc';

    private function raw(): array
    {
        return JsoncParser::parseFile(self::SCHEMA_FILE);
    }

    public function testSchemaPassesValidator(): void
    {
        StructuredSchemaValidator::validate('economy.vat.filingProfileCz', $this->raw());
        $this->expectNotToPerformAssertions();
    }

    public function testSchemaLoadsAndCarriesExpectedGroups(): void
    {
        $schema = StructuredSchema::fromArray(
            'economy.vat.filingProfileCz',
            ConfigLocalizer::localize($this->raw(), 'cs'),
        );

        $this->assertSame('economy.vat.filingProfileCz/2026', $schema->key());
        $this->assertSame(
            ['subject', 'office', 'address', 'contact', 'authorized', 'preparer', 'signatory'],
            array_keys($schema->groups),
        );
        $this->assertSame('Daňový subjekt', $schema->groupName('subject'));
        // Každé pole má skupinu — jinak by se v detailu ocitlo v „Obecné".
        foreach ($schema->fields as $field) {
            $this->assertNotNull($field->group, "field {$field->id} has no group");
        }
    }

    public function testFieldsCoverTheFilingHeaderAttributes(): void
    {
        $schema = StructuredSchema::fromArray(
            'economy.vat.filingProfileCz',
            ConfigLocalizer::localize($this->raw(), 'cs'),
        );

        // Atributy věty P, které starý Shipard plnil z properties
        // (VatReturnProperties::loadProperties) — jejich sada je smluvní
        // rozsah profilu, dokud ho F3 neověří proti XSD.
        $expected = [
            'typ_ds', 'c_okec', 'c_ufo', 'c_pracufo',
            'ulice', 'c_pop', 'c_orient', 'naz_obce', 'psc', 'stat',
            'c_telef', 'email', 'id_dats',
            'opr_jmeno', 'opr_prijmeni', 'opr_postaveni',
            'sest_jmeno', 'sest_prijmeni', 'sest_telef',
            'zast_typ', 'zast_kod', 'zast_nazev', 'zast_ic',
            'zast_jmeno', 'zast_prijmeni', 'zast_dat_nar', 'zast_ev_cislo',
        ];

        $this->assertSame($expected, array_keys($schema->fields));
    }

    public function testOnlyTaxpayerTypeIsRequired(): void
    {
        $schema = StructuredSchema::fromArray(
            'economy.vat.filingProfileCz',
            ConfigLocalizer::localize($this->raw(), 'cs'),
        );

        $required = [];
        foreach ($schema->fields as $field) {
            if ($field->required) {
                $required[] = $field->id;
            }
        }
        // Povinnost platí jen v neprázdném profilu (StructuredFieldValidator),
        // takže registraci k DPH bez podacích údajů jde pořád uložit.
        $this->assertSame(['typ_ds'], $required);
    }

    public function testEveryReferencedCfgItemIsRegisteredInSomeModule(): void
    {
        $schema = StructuredSchema::fromArray(
            'economy.vat.filingProfileCz',
            ConfigLocalizer::localize($this->raw(), 'cs'),
        );
        $registered = $this->registeredCfgItems();

        $enumFields = 0;
        foreach ($schema->fields as $field) {
            if (!in_array($field->type, StructuredField::ENUM_TYPES, true)) {
                continue;
            }
            $enumFields++;
            $this->assertArrayHasKey(
                (string) $field->cfgItem,
                $registered,
                "field {$field->id} points to unregistered cfgItem '{$field->cfgItem}'",
            );
            $data = JsoncParser::parseFile($registered[$field->cfgItem]);
            $this->assertNotSame([], $data, "cfgItem '{$field->cfgItem}' is empty");
        }
        $this->assertGreaterThan(0, $enumFields);
    }

    public function testExtensionAddsTheColumnToVatRegistrations(): void
    {
        $result = SchemaLoader::loadResolvedTables(
            new ModulePathResolver([self::MODULES]),
            ['economy.vat'],
        );

        $this->assertSame([], $result['errors']);
        $def = $result['tables']['economy_codebooks_vat_registrations'] ?? null;
        $this->assertNotNull($def, 'registrations table not resolved');
        $this->assertSame(
            ['filing_profile' => 'economy.vat.filingProfileCz'],
            $def->getStructuredColumns(),
        );
    }

    /**
     * Invariant, který jinak drží až `ds-upgrade`: každý sloupec se `schema`
     * musí mít cfgItem registrovaný v nějakém modulu, jinak upgrade DS
     * s tím modulem skončí chybou.
     */
    public function testEveryStructuredColumnInTheRepoHasItsSchemaRegistered(): void
    {
        $registered = $this->registeredCfgItems();
        $modules = [];
        foreach (glob(self::MODULES . '/*/*/module.jsonc') ?: [] as $file) {
            $modules[] = JsoncParser::parseFile($file)['id'];
        }

        $result = SchemaLoader::loadResolvedTables(new ModulePathResolver([self::MODULES]), $modules);
        $checked = 0;
        foreach ($result['tables'] as $table => $def) {
            foreach ($def->getStructuredColumns() as $column => $cfgId) {
                $checked++;
                $this->assertArrayHasKey(
                    $cfgId,
                    $registered,
                    "{$table}.{$column} references unregistered schema cfgItem '{$cfgId}'",
                );
                StructuredSchemaValidator::validate($cfgId, JsoncParser::parseFile($registered[$cfgId]));
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * cfgItem id => cesta k souboru ze všech `module.jsonc` v repozitáři.
     *
     * @return array<string, string>
     */
    private function registeredCfgItems(): array
    {
        $out = [];
        $files = glob(self::MODULES . '/*/*/module.jsonc') ?: [];
        foreach ($files as $file) {
            $module = JsoncParser::parseFile($file);
            foreach ($module['config'] ?? [] as $entry) {
                if (isset($entry['id'], $entry['file'])) {
                    $out[$entry['id']] = dirname($file) . '/' . $entry['file'];
                }
            }
        }
        return $out;
    }
}
