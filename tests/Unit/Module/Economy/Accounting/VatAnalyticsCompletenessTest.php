<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Test úplnosti DPH analytik (W2/W3 z tasks/accounting-vat-analytics.md):
 * pro každý kód z vat-cz.jsonc, který může vyprodukovat nenulovou daň
 * (nenulová hodnota ve vatPercents NEBO cíl reverseVatCode), musí existovat
 *
 *   1. mapovací řádek {cat: vat, accountMask: 343{NNN}, query: {vat_code}}
 *      v accountingRules.cz.jsonc,
 *   2. účet 343{NNN} v accountChartDefault.jsonc i accountChartNpo.jsonc.
 *
 * Programová kontrola — žádný ruční seznam; nový kód v číselníku bez
 * mapování/účtu tenhle test shodí.
 */
class VatAnalyticsCompletenessTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    /** @return list<string> kódy s možnou nenulovou daní */
    private function codesWithPossibleTax(): array
    {
        $cfg = JsoncParser::parseFile(self::MODULES . '/world/vat/config/vat-cz.jsonc');

        $nonzero = [];
        foreach ($cfg['vatPercents'] as $p) {
            if ((float) $p['value'] !== 0.0) {
                $nonzero[(string) $p['code']] = true;
            }
        }
        $reverseTargets = [];
        foreach ($cfg['vatCodes'] as $def) {
            if (!empty($def['reverseVatCode'])) {
                $reverseTargets[(string) $def['reverseVatCode']] = true;
            }
        }

        $codes = [];
        foreach (array_keys($cfg['vatCodes']) as $code) {
            if (isset($nonzero[$code]) || isset($reverseTargets[$code])) {
                $codes[] = (string) $code;
            }
        }
        $this->assertNotEmpty($codes);
        return $codes;
    }

    private function expectedAccount(string $code): string
    {
        $dash = strpos($code, '-');
        $this->assertNotFalse($dash, "Kód {$code} nemá tvar {country}-{NNN}");
        return '343' . substr($code, $dash + 1);
    }

    public function testEveryTaxProducingCodeHasMappingRow(): void
    {
        $rules = JsoncParser::parseFile(
            self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc',
        );

        $mapped = [];
        foreach ($rules['accounts'] as $entry) {
            if (($entry['cat'] ?? null) === 'vat' && isset($entry['query']['vat_code'])) {
                $mapped[(string) $entry['query']['vat_code']] = (string) $entry['accountMask'];
            }
        }

        foreach ($this->codesWithPossibleTax() as $code) {
            $this->assertArrayHasKey(
                $code,
                $mapped,
                "Předpis nemá mapovací řádek pro {$code}",
            );
            $this->assertSame(
                $this->expectedAccount($code),
                $mapped[$code],
                "Maska pro {$code} nedodržuje konvenci 343{NNN}",
            );
        }
    }

    public function testFallbackMaskIsRestrictedToDomesticCodes(): void
    {
        $rules = JsoncParser::parseFile(
            self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc',
        );

        $fallbacks = array_values(array_filter(
            $rules['accounts'],
            fn($e) => ($e['cat'] ?? null) === 'vat' && ($e['accountMask'] ?? null) === '343',
        ));
        $this->assertCount(1, $fallbacks, 'Očekáván právě jeden fallback s maskou 343');
        $this->assertSame(
            ['vat_code_country' => 'cz'],
            $fallbacks[0]['query'] ?? null,
            'Fallback 343 musí být omezen na vat_code_country = cz',
        );
    }

    public function testEveryTaxProducingCodeHasSeedAccountInBothCharts(): void
    {
        $charts = [
            'default' => self::MODULES . '/economy/accounting/config/accountChartDefault.jsonc',
            'npo'     => self::MODULES . '/economy/accounting/config/accountChartNpo.jsonc',
        ];

        foreach ($charts as $label => $path) {
            $numbers = array_flip(array_map(
                fn($e) => (string) $e['number'],
                JsoncParser::parseFile($path),
            ));
            foreach ($this->codesWithPossibleTax() as $code) {
                $account = $this->expectedAccount($code);
                $this->assertArrayHasKey(
                    $account,
                    $numbers,
                    "Rozvrh {$label} nemá účet {$account} (kód {$code})",
                );
            }
        }
    }
}
