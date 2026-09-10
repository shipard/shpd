<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\SchemaLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\StructuredFields\StructuredField;
use Shipard\Core\StructuredFields\StructuredSchema;
use Shipard\Core\StructuredFields\StructuredSchemaValidator;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\FilingHeaderSchema;

/**
 * Předvyplnění hlavičky podání z profilu podatele (#55 Fáze 3, X2).
 * Testuje se proti **skutečným schématům** z modulu — kdyby se z nich
 * pole ztratilo, prefill by ho tiše přestal kopírovat.
 */
class FilingHeaderSchemaTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const CONFIG  = self::MODULES . '/economy/vat/config';

    public function testSchemaIsChosenByReportType(): void
    {
        $this->assertSame('economy.vat.filingHeaderCzDp3', FilingHeaderSchema::forReportType('return'));
        $this->assertSame('economy.vat.filingHeaderCzKh1', FilingHeaderSchema::forReportType('cs'));
        $this->assertSame('economy.vat.filingHeaderCzShv', FilingHeaderSchema::forReportType('rs'));
    }

    public function testUnknownTypeLeavesStaticColumnSchemaInCharge(): void
    {
        $this->assertNull(FilingHeaderSchema::forReportType('dppo'));
        $this->assertNull(FilingHeaderSchema::forReportType(null));
        $this->assertNull(FilingHeaderSchema::forReportType(42));
    }

    public function testProfileValuesAreCopiedAndEmptyOnesSkipped(): void
    {
        $values = FilingHeaderSchema::prefill(
            $this->schema('Dp3'),
            [
                '_schema'  => 'economy.vat.filingProfileCz/2026',
                'typ_ds'   => 'P',
                'c_ufo'    => '464',
                'c_okec'   => '620200',
                'naz_obce' => 'Zlín',
                'email'    => '',
                'ulice'    => null,
            ],
            [],
            [],
        );

        $this->assertSame('P', $values['typ_ds']);
        $this->assertSame('464', $values['c_ufo']);
        $this->assertSame('620200', $values['c_okec']);
        $this->assertSame('Zlín', $values['naz_obce']);
        $this->assertArrayNotHasKey('email', $values, 'Prázdná hodnota se nekopíruje');
        $this->assertArrayNotHasKey('ulice', $values);
        $this->assertArrayNotHasKey('_schema', $values, 'Verzi hodnoty určuje až serializace');
    }

    /**
     * Kontrolní hlášení nemá ve větě D co editovat — `c_okec` v jeho
     * schématu není a z profilu se nesmí protlačit.
     */
    public function testFieldsMissingFromTargetSchemaAreNotCopied(): void
    {
        $profile = ['typ_ds' => 'P', 'c_okec' => '620200', 'email' => 'ucto@example.com'];

        $control = FilingHeaderSchema::prefill($this->schema('Kh1'), $profile, [], []);
        $this->assertArrayNotHasKey('c_okec', $control);
        $this->assertSame('ucto@example.com', $control['email']);

        // Souhrnné hlášení nemá ve větě P e-mail ani telefon.
        $recap = FilingHeaderSchema::prefill($this->schema('Shv'), $profile, [], []);
        $this->assertArrayNotHasKey('email', $recap);
        $this->assertArrayNotHasKey('c_okec', $recap);
        $this->assertSame('P', $recap['typ_ds']);
    }

    public function testIdentityAndFilingDefaultsOverrideProfile(): void
    {
        $values = FilingHeaderSchema::prefill(
            $this->schema('Dp3'),
            ['typ_ds' => 'P', 'zkrobchjm' => 'Staré jméno', 'c_ufo' => '464'],
            ['zkrobchjm' => 'Ukázka s.r.o.'],
            ['dic' => '12345678', 'trans' => true],
        );

        $this->assertSame('Ukázka s.r.o.', $values['zkrobchjm']);
        $this->assertSame('12345678', $values['dic']);
        $this->assertTrue($values['trans']);
    }

    public function testSchemaDefaultFillsWhatNobodySupplied(): void
    {
        $values = FilingHeaderSchema::prefill($this->schema('Dp3'), [], [], []);

        $this->assertSame('P', $values['typ_platce'], 'Typ podávající osoby má default ve schématu');
        $this->assertArrayNotHasKey('c_ufo', $values);
    }

    /** `false` je hodnota — nevznikla-li daňová povinnost, jde do XML „N". */
    public function testFalseIsKeptAsValue(): void
    {
        $values = FilingHeaderSchema::prefill($this->schema('Dp3'), [], [], ['trans' => false]);
        $this->assertArrayHasKey('trans', $values);
        $this->assertFalse($values['trans']);
    }

    public function testUnknownKeysFromProfileNeverLeakIntoHeader(): void
    {
        $values = FilingHeaderSchema::prefill(
            $this->schema('Dp3'),
            ['id_dats' => 'abc1234', 'typ_ds' => 'P'],
            [],
            [],
        );
        $this->assertArrayNotHasKey('id_dats', $values, 'ID datové schránky ve větě P není');
    }

    // ── Invarianty schémat ──────────────────────────────────────────────

    /**
     * Schémata kontrolního a souhrnného hlášení nejsou na žádném sloupci
     * (vybírá je hook), takže je `ds-upgrade` ani obecný invariant nad
     * strukturovanými sloupci nezkontroluje — musí je zkontrolovat tenhle
     * test. Bez toho by překlep v poli spadl až při ukládání hlavičky KH.
     */
    public function testEverySchemaPassesValidatorAndHasGroupedFields(): void
    {
        foreach (FilingHeaderSchema::CFG_ITEM_BY_TYPE as $cfgItem) {
            $raw = JsoncParser::parseFile($this->registeredCfgItems()[$cfgItem]);
            StructuredSchemaValidator::validate($cfgItem, $raw);

            $schema = StructuredSchema::fromArray($cfgItem, $raw);
            $this->assertSame('2026', $schema->version);
            foreach ($schema->fields as $field) {
                $this->assertNotNull(
                    $field->group,
                    "{$cfgItem}: pole {$field->id} nemá skupinu — v detailu by spadlo do „Obecné\"",
                );
                $this->assertArrayHasKey($field->group, $schema->groups, "{$cfgItem}: {$field->id}");
            }
        }
    }

    public function testEveryReferencedCodebookIsRegisteredAndNonEmpty(): void
    {
        $registered = $this->registeredCfgItems();
        $checked    = 0;

        foreach (FilingHeaderSchema::CFG_ITEM_BY_TYPE as $cfgItem) {
            $schema = StructuredSchema::fromArray($cfgItem, JsoncParser::parseFile($registered[$cfgItem]));
            foreach ($schema->fields as $field) {
                if (!in_array($field->type, StructuredField::ENUM_TYPES, true)) {
                    continue;
                }
                $checked++;
                $this->assertArrayHasKey(
                    (string) $field->cfgItem,
                    $registered,
                    "{$cfgItem}: pole {$field->id} ukazuje na neregistrovaný číselník '{$field->cfgItem}'",
                );
                $this->assertNotSame([], JsoncParser::parseFile($registered[$field->cfgItem]));
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * Sloupec `header` musí nést statické schéma (přiznání) a **nesmí být
     * `system`** — systémové sloupce zahazuje FormController i s jejich
     * virtuálními poli, takže by se hlavička z formuláře neuložila (X15).
     */
    public function testHeaderColumnIsStructuredAndWritable(): void
    {
        $result = SchemaLoader::loadResolvedTables(new ModulePathResolver([self::MODULES]), ['economy.vat']);
        $this->assertSame([], $result['errors']);

        $def = $result['tables']['economy_vat_filings'] ?? null;
        $this->assertNotNull($def);
        $this->assertSame(
            'economy.vat.filingHeaderCzDp3',
            $def->getStructuredColumns()[FilingHeaderSchema::COLUMN] ?? null,
        );

        foreach ($def->columns as $column) {
            if ($column->id === FilingHeaderSchema::COLUMN) {
                $this->assertFalse($column->system, 'systémový sloupec by formulář neuložil');
                return;
            }
        }
        $this->fail('sloupec header v tabulce není');
    }

    private function schema(string $document): StructuredSchema
    {
        return StructuredSchema::fromArray(
            'economy.vat.filingHeaderCz' . $document,
            JsoncParser::parseFile(self::CONFIG . "/filingHeaderCz{$document}.jsonc"),
        );
    }

    /**
     * cfgItem id → cesta k souboru ze všech `module.jsonc` v repozitáři.
     *
     * @return array<string, string>
     */
    private function registeredCfgItems(): array
    {
        $out = [];
        foreach (glob(self::MODULES . '/*/*/module.jsonc') ?: [] as $file) {
            foreach (JsoncParser::parseFile($file)['config'] ?? [] as $entry) {
                if (isset($entry['id'], $entry['file'])) {
                    $out[$entry['id']] = dirname($file) . '/' . $entry['file'];
                }
            }
        }
        return $out;
    }
}
