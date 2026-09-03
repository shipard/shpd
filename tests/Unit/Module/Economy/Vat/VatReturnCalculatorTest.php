<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatReturnCalculator;

/**
 * Dopočty DPHDP3 (46, 62–65) na syntetických datech.
 */
class VatReturnCalculatorTest extends TestCase
{
    private function calculator(): VatReturnCalculator
    {
        return new VatReturnCalculator(new VatOutputsMapping(['vatOutputs' => [
            'cz-120' => ['dp3' => ['row' => 1], 'kh' => null, 'sh' => null],
            'cz-121' => ['dp3' => ['row' => 2], 'kh' => null, 'sh' => null],
            'cz-110' => ['dp3' => ['row' => 40, 'col' => 'full'], 'kh' => null, 'sh' => null],
            'cz-118' => ['dp3' => ['row' => 40, 'col' => 'reduced'], 'kh' => null, 'sh' => null],
            'cz-115' => ['dp3' => ['row' => 43, 'col' => 'full'], 'kh' => null, 'sh' => null],
            'cz-203' => ['dp3' => ['row' => 10], 'kh' => null, 'sh' => null],
            'cz-201' => ['dp3' => ['row' => 20], 'kh' => null, 'sh' => null],
            'cz-122' => ['dp3' => null, 'kh' => null, 'sh' => null],
        ]]));
    }

    /** @return array<string, mixed> */
    private function doc(array $recap, int $id = 1): array
    {
        return ['id' => $id, 'doc_number' => "D-{$id}", 'recap' => $recap];
    }

    public function testRowsSumPerRowAndColumn(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-120', 'base_dom' => 500.0, 'tax_dom' => 105.0],
                ['vat_code' => 'cz-121', 'base_dom' => 200.0, 'tax_dom' => 24.0],
                ['vat_code' => 'cz-122', 'base_dom' => 999.0, 'tax_dom' => 0.0], // dp3 null → mimo
            ]),
            $this->doc([
                ['vat_code' => 'cz-110', 'base_dom' => 2000.0, 'tax_dom' => 420.0],
                ['vat_code' => 'cz-118', 'base_dom' => 300.0, 'tax_dom' => 63.0],
            ], 2),
        ]);

        $this->assertSame(
            ['base' => 1500.0, 'taxFull' => 315.0, 'taxReduced' => 0.0],
            $result['rows'][1],
        );
        $this->assertSame(
            ['base' => 200.0, 'taxFull' => 24.0, 'taxReduced' => 0.0],
            $result['rows'][2],
        );
        // Řádek 40: plný i krácený sloupec, základ dohromady.
        $this->assertSame(
            ['base' => 2300.0, 'taxFull' => 420.0, 'taxReduced' => 63.0],
            $result['rows'][40],
        );
        $this->assertArrayNotHasKey(20, $result['rows']);
        // Řádky vzestupně.
        $this->assertSame([1, 2, 40], array_keys($result['rows']));
    }

    public function testComputedRow46SumsDeductions(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-110', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-118', 'base_dom' => 500.0, 'tax_dom' => 105.0],
                ['vat_code' => 'cz-115', 'base_dom' => 2000.0, 'tax_dom' => 420.0],
            ]),
        ]);

        $this->assertSame(
            ['base' => 3500.0, 'taxFull' => 630.0, 'taxReduced' => 105.0],
            $result['computed'][46],
        );
    }

    public function testOwnTaxLiability(): void
    {
        // Výstup 1000/210 (ř. 1) + samovyměření 500/105 (ř. 10) proti
        // odpočtu 400/84 (ř. 40): 62 = 315, 63 = 84 → vlastní daň 231.
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-203', 'base_dom' => 500.0, 'tax_dom' => 105.0],
                ['vat_code' => 'cz-110', 'base_dom' => 400.0, 'tax_dom' => 84.0],
            ]),
        ]);

        $this->assertSame(315.0, $result['computed'][62]['taxFull']);
        $this->assertSame(84.0, $result['computed'][63]['taxFull']);
        $this->assertSame(231.0, $result['computed'][64]['taxFull'], 'vlastní daň');
        $this->assertSame(0.0, $result['computed'][65]['taxFull']);
    }

    public function testExcessDeduction(): void
    {
        // Odpočet převyšuje daň na výstupu → nadměrný odpočet na ř. 65.
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 100.0, 'tax_dom' => 21.0],
                ['vat_code' => 'cz-110', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
            ]),
        ]);

        $this->assertSame(21.0, $result['computed'][62]['taxFull']);
        $this->assertSame(210.0, $result['computed'][63]['taxFull']);
        $this->assertSame(0.0, $result['computed'][64]['taxFull']);
        $this->assertSame(189.0, $result['computed'][65]['taxFull'], 'nadměrný odpočet');
    }

    public function testReducedDeductionDoesNotEnterRow63(): void
    {
        // Krácený odpočet (ř. 40 sloupec krácený) se jen vykazuje — bez
        // koeficientu (ř. 52, mimo scope M1) nevstupuje do nároku ř. 63.
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-118', 'base_dom' => 500.0, 'tax_dom' => 105.0],
            ]),
        ]);

        $this->assertSame(105.0, $result['computed'][46]['taxReduced']);
        $this->assertSame(0.0, $result['computed'][63]['taxFull']);
        $this->assertSame(210.0, $result['computed'][64]['taxFull']);
    }

    public function testBaseOnlyRowsDoNotAffectTax(): void
    {
        // Ř. 20 (dodání do EU) nese jen základ — 62/64 z něj nevzniká.
        $result = $this->calculator()->calculate([
            $this->doc([['vat_code' => 'cz-201', 'base_dom' => 9000.0, 'tax_dom' => 0.0]]),
        ]);

        $this->assertSame(['base' => 9000.0, 'taxFull' => 0.0, 'taxReduced' => 0.0], $result['rows'][20]);
        $this->assertSame(0.0, $result['computed'][62]['taxFull']);
        $this->assertSame(0.0, $result['computed'][64]['taxFull']);
        $this->assertSame(0.0, $result['computed'][65]['taxFull']);
    }

    public function testEmptySelection(): void
    {
        $result = $this->calculator()->calculate([]);

        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $result['computed'][46]['taxFull']);
        $this->assertSame(0.0, $result['computed'][64]['taxFull']);
        $this->assertSame(0.0, $result['computed'][65]['taxFull']);
    }
}
