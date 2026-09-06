<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\ControlStatementCalculator;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatReturnCalculator;

/**
 * Pokladní doklady a prodejky v DPH výstupech (#59 D11): kalkulátory typ
 * dokladu neřeší — směr dává DPH kód řádku (příjem výstupní cz-1xx, výdej
 * vstupní cz-1xx na řádku 40) a KH A4/A5 rozhoduje DIČ odběratele ze
 * snapshotu. Žádná změna kódu economy.vat, test jen fixuje kontrakt.
 */
class VatCashDocumentsTest extends TestCase
{
    private function mapping(): VatOutputsMapping
    {
        return new VatOutputsMapping(['vatOutputs' => [
            'cz-120' => ['dp3' => ['row' => 1], 'kh' => ['group' => 'A4A5'], 'sh' => null],
            'cz-110' => ['dp3' => ['row' => 40, 'col' => 'full'], 'kh' => ['group' => 'B2B3'], 'sh' => null],
        ]]);
    }

    public function testCashReceiptAndDisbursementLandOnOutputAndInputRows(): void
    {
        $result = (new VatReturnCalculator($this->mapping()))->calculate([
            // příjmový PD — prodej služby s výstupní daní
            ['id' => 1, 'doc_type' => 'cash', 'doc_number' => '31HP12600001',
                'recap' => [['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0]]],
            // výdajový PD — nákup s odpočtem
            ['id' => 2, 'doc_type' => 'cash', 'doc_number' => '31HP12600002',
                'recap' => [['vat_code' => 'cz-110', 'base_dom' => 500.0, 'tax_dom' => 105.0]]],
            // prodejka
            ['id' => 3, 'doc_type' => 'cashreg', 'doc_number' => '14HP12600001',
                'recap' => [['vat_code' => 'cz-120', 'base_dom' => 200.0, 'tax_dom' => 42.0]]],
        ]);

        $this->assertSame(['base' => 1200.0, 'taxFull' => 252.0, 'taxReduced' => 0.0], $result['rows'][1]);
        $this->assertSame(['base' => 500.0, 'taxFull' => 105.0, 'taxReduced' => 0.0], $result['rows'][40]);
    }

    public function testRetailSaleOverLimitSplitsByCustomerVatId(): void
    {
        $calculator = new ControlStatementCalculator($this->mapping(), ['cz-120' => ['category' => 'standard']]);
        $sale = [
            'id' => 5, 'doc_type' => 'cashreg', 'doc_number' => '14HP12600005',
            'partner_doc_number' => '', 'total_amount_dom' => 12100.0,
            'vat_duzp' => '2026-06-10', 'vat_dppd' => '2026-06-10',
            'supplier_vat_id' => 'CZ63478714',
            'recap' => [['vat_code' => 'cz-120', 'base_dom' => 10000.0, 'tax_dom' => 2100.0]],
        ];

        // prodejka s partnerem s CZ DIČ (customer_snapshot) → A4
        $result = $calculator->calculate([$sale + ['customer_vat_id' => 'CZ12345678']]);
        $this->assertCount(1, $result['sections']['A4']);
        $this->assertSame('14HP12600005', $result['sections']['A4'][0]['evidNumber']);
        $this->assertSame([], $result['sections']['A5']);

        // anonymní prodejka (bez snapshotu odběratele) → A5
        $result = $calculator->calculate([$sale + ['customer_vat_id' => '']]);
        $this->assertSame([], $result['sections']['A4']);
        $this->assertCount(1, $result['sections']['A5']);
    }
}
