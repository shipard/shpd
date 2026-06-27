<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\AccountingDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\AccountingDocs\AccountingDocument;

/**
 * Subclass cmnbkp — vyrovnanost, nepovinný hlavičkový partner, součty z řádků.
 * Bez DB: validate s db=null přeskočí kontrolu vlastní firmy; resolveRowsForCompute
 * čte řádky z $data['rows'].
 */
class AccountingDocumentTest extends TestCase
{
    /** Testovací subclass zpřístupňující protected sumTotals. */
    private function doc(): AccountingDocument
    {
        return new class extends AccountingDocument {
            public function sumTotalsPub(array &$data, array $rows): void
            {
                $this->sumTotals($data, [], $rows);
            }
        };
    }

    /**
     * Hlavička ve stavu 40 s povinnými poli; jen řádky se mění per test.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function head40(array $rows): array
    {
        return [
            'doc_type'        => 'cmnbkp',
            'docState'        => 40,
            'number_series'   => 1,
            'issue_date'      => '2026-06-10',
            'accounting_date' => '2026-06-10',
            'rows'            => $rows,
        ];
    }

    /** @return list<string> */
    private function codes(\Shipard\Core\Document\ValidationResult $r): array
    {
        return array_map(fn($e) => $e->code, $r->getErrors());
    }

    /** @return list<string> */
    private function columns(\Shipard\Core\Document\ValidationResult $r): array
    {
        return array_map(fn($e) => $e->column, $r->getErrors());
    }

    public function testBalancedDocWithoutHeadPartnerPasses(): void
    {
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 0, 'account' => 10, 'total_price' => 1000.0],
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 1, 'account' => 20, 'total_price' => 1000.0],
        ]);

        $result = $this->doc()->validate($data);

        // Vyrovnáno → žádná unbalanced chyba; partner nepovinný → žádná partner chyba.
        $this->assertNotContains('unbalanced', $this->codes($result));
        $this->assertNotContains('partner', $this->columns($result));
    }

    public function testUnbalancedDocFailsAt40(): void
    {
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 0, 'account' => 10, 'total_price' => 1000.0],
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 1, 'account' => 20, 'total_price' => 600.0],
        ]);

        $result = $this->doc()->validate($data);

        $this->assertContains('unbalanced', $this->codes($result));
    }

    public function testRowLevelRequirements(): void
    {
        $data = $this->head40([
            // chybí acc_side, acc.record bez účtu, nulová částka
            ['row_kind' => 1, 'operation' => 'acc.record', 'total_price' => 0.0],
            // acc.item bez položky
            ['row_kind' => 1, 'operation' => 'acc.item', 'acc_side' => 1, 'total_price' => 100.0],
        ]);

        $result = $this->doc()->validate($data);
        $codes = $this->codes($result);

        $this->assertContains('acc_side_required', $codes);
        $this->assertContains('account_required', $codes);
        $this->assertContains('amount_required', $codes);
        $this->assertContains('item_required', $codes);
    }

    public function testSumTotalsFromDebitRows(): void
    {
        $data = [];
        $rows = [
            ['row_kind' => 1, 'acc_side' => 0, 'total_price' => 1000.0],
            ['row_kind' => 1, 'acc_side' => 0, 'total_price' => 250.0],
            ['row_kind' => 1, 'acc_side' => 1, 'total_price' => 1250.0],
        ];

        $this->doc()->sumTotalsPub($data, $rows);

        $this->assertSame(1250.0, $data['total_amount']);
        $this->assertSame(1250.0, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat']);
        $this->assertSame(0.0, $data['total_rounding']);
    }
}
