<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Úplnost mapování DPH výstupů (tasks/taxes-phase01.md, issue #55, D3 —
 * vzor VatAnalyticsCompletenessTest): každý kód z world.vat.cz má záznam
 * ve vat-reports-cz.jsonc a naopak; DP3 řádek se shoduje s `vatReturnRow`
 * číselníku (dokud pole existuje — chrání před překlepem při přepisu);
 * tvary záznamů drží kontrakt configu. Programová kontrola — žádný ruční
 * seznam.
 */
class VatReportsMappingCompletenessTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    private const KH_GROUPS = ['A1', 'A2', 'A4A5', 'B1', 'B2B3'];
    /** Odpočtové řádky se sloupcem „V plné výši" / „Krácený odpočet". */
    private const DP3_DEDUCTION_ROWS = [40, 41, 43, 44];

    /** @return array<string, array<string, mixed>> */
    private function vatCodes(): array
    {
        $cfg = JsoncParser::parseFile(self::MODULES . '/world/vat/config/vat-cz.jsonc');
        $this->assertNotEmpty($cfg['vatCodes']);
        return $cfg['vatCodes'];
    }

    /** @return array<string, mixed> celý config vat-reports-cz.jsonc */
    private function reportsConfig(): array
    {
        return JsoncParser::parseFile(
            self::MODULES . '/economy/vat/config/vat-reports-cz.jsonc',
        );
    }

    public function testEveryCodeHasMappingAndNothingExtra(): void
    {
        $codes   = array_keys($this->vatCodes());
        $mapping = array_keys($this->reportsConfig()['vatOutputs']);

        $this->assertSame(
            [],
            array_diff($codes, $mapping),
            'Kódy číselníku bez záznamu v mapování (i vyloučení musí být explicitní null)',
        );
        $this->assertSame(
            [],
            array_diff($mapping, $codes),
            'Záznamy mapování bez kódu v číselníku',
        );
    }

    public function testDp3RowsMatchVatReturnRow(): void
    {
        $codes   = $this->vatCodes();
        $mapping = $this->reportsConfig()['vatOutputs'];

        foreach ($codes as $code => $def) {
            if (!array_key_exists('vatReturnRow', $def)) {
                // Odstranění vatReturnRow z world.vat je samostatný úklid
                // po M1 — pak tato větev vyřadí kód z kontroly.
                continue;
            }
            $expected = (int) $def['vatReturnRow'];
            $dp3      = $mapping[$code]['dp3'] ?? null;
            if ($expected === 0) {
                $this->assertNull($dp3, "Kód {$code}: vatReturnRow 0, ale mapování má dp3 řádek");
                continue;
            }
            $this->assertNotNull($dp3, "Kód {$code}: vatReturnRow {$expected}, ale mapování má dp3 null");
            $this->assertSame(
                $expected,
                (int) $dp3['row'],
                "Kód {$code}: dp3.row nesouhlasí s vatReturnRow číselníku",
            );
        }
    }

    public function testRecordShapes(): void
    {
        $mapping = $this->reportsConfig()['vatOutputs'];

        foreach ($mapping as $code => $record) {
            $this->assertSame(
                ['dp3', 'kh', 'sh'],
                array_keys($record),
                "Kód {$code}: záznam musí mít právě klíče dp3/kh/sh",
            );

            $dp3 = $record['dp3'];
            if ($dp3 !== null) {
                $this->assertIsInt($dp3['row'], "Kód {$code}: dp3.row musí být int");
                if (isset($dp3['col'])) {
                    $this->assertContains($dp3['col'], ['full', 'reduced'], "Kód {$code}: dp3.col");
                    $this->assertContains(
                        $dp3['row'],
                        self::DP3_DEDUCTION_ROWS,
                        "Kód {$code}: dp3.col patří jen odpočtovým řádkům 40/41/43/44",
                    );
                } else {
                    $this->assertNotContains(
                        $dp3['row'],
                        self::DP3_DEDUCTION_ROWS,
                        "Kód {$code}: odpočtový řádek {$dp3['row']} musí mít dp3.col",
                    );
                }
            }

            $kh = $record['kh'];
            if ($kh !== null) {
                $this->assertContains($kh['group'], self::KH_GROUPS, "Kód {$code}: kh.group");
                if (in_array($kh['group'], ['A1', 'B1'], true)) {
                    $this->assertContains(
                        $kh['kodPredPl'] ?? null,
                        [4, 5],
                        "Kód {$code}: PDP sekce {$kh['group']} vyžaduje kodPredPl 4|5",
                    );
                } else {
                    $this->assertArrayNotHasKey(
                        'kodPredPl',
                        $kh,
                        "Kód {$code}: kodPredPl patří jen sekcím A1/B1",
                    );
                }
            }

            $sh = $record['sh'];
            if ($sh !== null) {
                $this->assertContains($sh['kod'], [0, 3], "Kód {$code}: sh.kod musí být 0 (zboží) | 3 (služby)");
            }
        }
    }

    public function testEveryUsedDp3RowHasLabel(): void
    {
        $config = $this->reportsConfig();
        $labels = $config['dp3Rows'];

        $usedRows = [];
        foreach ($config['vatOutputs'] as $record) {
            if ($record['dp3'] !== null) {
                $usedRows[(int) $record['dp3']['row']] = true;
            }
        }
        // Dopočtené řádky živého přiznání (46, 62–65).
        foreach ([46, 62, 63, 64, 65] as $computed) {
            $usedRows[$computed] = true;
        }

        foreach (array_keys($usedRows) as $row) {
            $this->assertArrayHasKey(
                (string) $row,
                $labels,
                "dp3Rows: chybí popisek řádku {$row}",
            );
            $this->assertNotSame('', (string) ($labels[(string) $row]['label'] ?? ''), "dp3Rows[{$row}]: prázdný label");
            $this->assertNotSame('', (string) ($labels[(string) $row]['label:cs'] ?? ''), "dp3Rows[{$row}]: prázdný label:cs");
        }
    }
}
