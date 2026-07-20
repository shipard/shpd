<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Úplnost sazeb samovyměřovacích kódů: každý reverse kód, na který odkazuje
 * nějaký primární kód přes `reverseVatCode`, musí mít alespoň jeden záznam ve
 * `vatPercents`. Konfigurace nemá mít definované kódy bez sazeb — je to
 * nášlapná mina pro jakékoli použití resolveVatPct().
 */
class VatReverseCodeRatesTest extends TestCase
{
    private const VAT_CZ = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';

    public function testEveryReverseTargetCodeHasRates(): void
    {
        $cfg = JsoncParser::parseFile(self::VAT_CZ);

        $withRates = [];
        foreach ($cfg['vatPercents'] as $p) {
            $withRates[(string) $p['code']] = true;
        }

        $reverseTargets = [];
        foreach ($cfg['vatCodes'] as $def) {
            if (!empty($def['reverseVatCode'])) {
                $reverseTargets[(string) $def['reverseVatCode']] = true;
            }
        }
        $this->assertNotEmpty($reverseTargets);

        foreach (array_keys($reverseTargets) as $code) {
            $this->assertArrayHasKey(
                $code,
                $withRates,
                "Reverse kód {$code} nemá žádný záznam ve vatPercents",
            );
        }
    }
}
