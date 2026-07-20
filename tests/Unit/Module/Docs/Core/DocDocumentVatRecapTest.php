<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentVatRecapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_recap_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * Build a minimal world.vat.cz cfgItem with the codes we test.
     */
    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'world.vat.cz' => [
                'vatCategories' => [
                    'standard' => ['name' => 'Standard'],
                    'reduced'  => ['name' => 'Reduced'],
                    'exempt'   => ['name' => 'Exempt'],
                ],
                'vatCodes' => [
                    'cz-110' => [
                        'category' => 'standard', 'place' => 'domestic', 'direction' => 'input',
                    ],
                    'cz-111' => [
                        'category' => 'reduced', 'place' => 'domestic', 'direction' => 'input',
                    ],
                    'cz-115' => [
                        'category' => 'standard', 'place' => 'domestic', 'direction' => 'input',
                        'noPayTax' => 1, 'sumTax' => 0,
                        'reverseVatCode' => 'cz-203', 'reverseCharge' => 1,
                    ],
                    'cz-203' => [
                        'category' => 'standard', 'place' => 'domestic', 'direction' => 'output',
                        'noPayTax' => 1, 'hidden' => 1, 'sumBase' => 0, 'sumTax' => 0, 'sumTotal' => 0,
                    ],
                    // Osvobozeno (exempt) — valid code with a 0% rate.
                    'cz-123' => [
                        'category' => 'exempt', 'place' => 'domestic', 'direction' => 'output',
                    ],
                ],
                // Reverse kód cz-203 zde ZÁMĚRNĚ nemá vatPercents záznam —
                // pár musí dědit sazbu primární skupiny (cz-115), ne volat
                // rate resolver (ten by na chybějící sazbu vyhodil výjimku).
                'vatPercents' => [
                    ['code' => 'cz-110', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 21],
                    ['code' => 'cz-111', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 12],
                    ['code' => 'cz-115', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 21],
                    ['code' => 'cz-123', 'from' => '0000-00-00', 'to' => '0000-00-00', 'value' => 0],
                ],
                'vatNotes' => [],
            ],
        ];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    private function dbWithCountry(string $country): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['country' => $country]));
        return $db;
    }

    private function buildDoc(): TestableDocsHeadsDocument
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithCountry('cz'));
        $doc->setConfig($this->buildConfig());
        return $doc;
    }

    public function testEmptyRowsReturnsEmptyRecap(): void
    {
        $doc = $this->buildDoc();
        $data = ['rows' => [], 'vat_registration' => 1, 'vat_duzp' => '2026-05-06'];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertSame([], $recap);
    }

    public function testNoVatRegistrationReturnsEmptyRecap(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->buildConfig());
        // No DB → resolveCountryFromVatRegistration returns null

        $data = [
            'rows' => [['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100]],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $this->assertSame([], $doc->buildVatRecapitulationPub($data));
    }

    public function testSingleStandardCodeOneRow(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame('cz-110', $recap[0]['vat_code']);
        $this->assertSame(200.0, $recap[0]['base']);
        $this->assertSame(42.0, $recap[0]['tax']);
        $this->assertSame(242.0, $recap[0]['total']);
        $this->assertSame(1, $recap[0]['sum_base']);
        $this->assertSame(1, $recap[0]['sum_tax']);
        $this->assertSame(1, $recap[0]['sum_total']);
        $this->assertSame(0, $recap[0]['is_reverse_pair']);
    }

    public function testGroupingAcrossRowsSameCode(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(200.0, $recap[0]['base']);
        $this->assertSame(42.0, $recap[0]['tax']);
    }

    public function testReverseChargeGeneratesPair(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);

        // Primary cz-115 — samovyměření: tax=42 (nárok na odpočet),
        // total ale jen základ (noPayTax — daň se dodavateli neplatí)
        $this->assertSame('cz-115', $recap[0]['vat_code']);
        $this->assertSame(200.0, $recap[0]['base']);
        $this->assertSame(42.0, $recap[0]['tax']);
        $this->assertSame(200.0, $recap[0]['total']);
        $this->assertSame(0, $recap[0]['sum_tax']);
        $this->assertSame(0, $recap[0]['is_reverse_pair']);

        // Paired cz-203 — sazbu i daň dědí z primární skupiny (21 %), base=200,
        // tax=42, all sum_*=0, is_reverse_pair=1. cz-203 nemá vlastní
        // vatPercents (viz buildConfig) — důkaz, že se resolver nevolá.
        $this->assertSame('cz-203', $recap[1]['vat_code']);
        $this->assertSame(21.0, $recap[1]['vat_pct']);
        $this->assertSame(200.0, $recap[1]['base']);
        $this->assertSame(42.0, $recap[1]['tax']);
        $this->assertSame(0, $recap[1]['sum_base']);
        $this->assertSame(0, $recap[1]['sum_tax']);
        $this->assertSame(0, $recap[1]['sum_total']);
        $this->assertSame(1, $recap[1]['is_reverse_pair']);
    }

    /**
     * "Z ceny celkem" (vat_mode = 2): the row's total_price is VAT-inclusive
     * and calculateRowVat() has already back-calculated vat_base. The recap
     * must aggregate vat_base, not total_price, so the summary base/tax/total
     * match the per-row figures.
     *
     * Mirrors issued invoice 12610005: total_price=7000 incl. 12% VAT →
     * base=6250, tax=750, total=7000 (not base=7000, tax=840, total=7840).
     */
    public function testVatInclusiveModeAggregatesBaseNotTotal(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                [
                    'row_kind'    => 1,
                    'vat_code'    => 'cz-111',
                    'vat_pct'     => 12,
                    'total_price' => 7000,   // VAT-inclusive
                    'vat_base'    => 6250.0, // computed by calculateRowVat (7000 / 1.12)
                    'vat_amount'  => 750.0,
                    'vat_total'   => 7000.0,
                ],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame('cz-111', $recap[0]['vat_code']);
        $this->assertSame(6250.0, $recap[0]['base']);
        $this->assertSame(750.0, $recap[0]['tax']);
        $this->assertSame(7000.0, $recap[0]['total']);
    }

    /**
     * Osvobozeno / 0% rows (e.g. cz-123, vat_pct=0) must appear in the recap
     * with their base and tax=0 — they must NOT be dropped just because the
     * percent is zero, or their base vanishes from the document totals.
     */
    public function testZeroPercentExemptRowIncludedInRecap(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 1000],
                ['row_kind' => 1, 'vat_code' => 'cz-123', 'vat_pct' => 0,  'total_price' => 500],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);
        $byCode = array_column($recap, null, 'vat_code');

        $this->assertArrayHasKey('cz-123', $byCode);
        $this->assertSame(500.0, $byCode['cz-123']['base']);
        $this->assertSame(0.0, $byCode['cz-123']['tax']);
        $this->assertSame(500.0, $byCode['cz-123']['total']);

        $this->assertSame(1000.0, $byCode['cz-110']['base']);
        $this->assertSame(210.0, $byCode['cz-110']['tax']);
    }

    public function testNullVatCodeRowSkipped(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => null, 'vat_pct' => null, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame('cz-110', $recap[0]['vat_code']);
        $this->assertSame(200.0, $recap[0]['base']);
    }

    public function testTextRowSkipped(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 0, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 200],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap);
        $this->assertSame(200.0, $recap[0]['base']);
    }

    public function testExchangeRateAppliedToDom(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 25.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertSame(2500.0, $recap[0]['base_dom']);
        $this->assertSame(525.0, $recap[0]['tax_dom']);
        $this->assertSame(3025.0, $recap[0]['total_dom']);
    }

    public function testUnknownVatCodeThrows(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-XYZ', 'vat_pct' => 21, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];

        // Neznámý kód je datová chyba — nesmí tiše vypadnout ze součtů.
        $this->expectException(\DomainException::class);
        $doc->buildVatRecapitulationPub($data);
    }

    public function testDifferentCodesProduceMultipleRows(): void
    {
        $doc = $this->buildDoc();
        $data = [
            'rows' => [
                ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21, 'total_price' => 100],
                ['row_kind' => 1, 'vat_code' => 'cz-111', 'vat_pct' => 12, 'total_price' => 100],
            ],
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(2, $recap);
        $codes = array_column($recap, 'vat_code');
        $this->assertContains('cz-110', $codes);
        $this->assertContains('cz-111', $codes);
    }
}
