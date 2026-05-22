<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;

class DocumentValidatorTest extends TestCase
{
    private DocumentValidator $v;

    protected function setUp(): void
    {
        $this->v = new DocumentValidator();
    }

    public function testMissingIssueDateProducesRequiredError(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $paths = array_column($issues, 'path');
        $this->assertContains('dates.issueDate', $paths);
        $required = $this->findByPath($issues, 'dates.issueDate');
        $this->assertSame('error', $required['severity']);
        $this->assertSame('required', $required['code']);
    }

    public function testEmptyRowsProducesRequiredError(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [],
        ]);
        $paths = array_column($issues, 'path');
        $this->assertContains('rows', $paths);
    }

    public function testInvoiceReceivedRequiresSupplier(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $supplierIssue = $this->findByPath($issues, 'supplier');
        $this->assertNotNull($supplierIssue);
        $this->assertSame('error', $supplierIssue['severity']);
    }

    public function testInvoiceIssuedRequiresCustomer(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceIssued',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $customerIssue = $this->findByPath($issues, 'customer');
        $this->assertNotNull($customerIssue);
        $this->assertSame('error', $customerIssue['severity']);
    }

    public function testTotalsMismatchProducesWarningWhenNoVariantFits(): void
    {
        // None of the variants lands within 0.01 — neither base-sum nor
        // base×(1+pct) and there is no vatRecap.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1499.50],
        ]);
        $mismatch = $this->findByCode($issues, 'totals_mismatch');
        $this->assertNotNull($mismatch);
        $this->assertSame('warning', $mismatch['severity']);
        // Both computed variants should appear in the detail string.
        $this->assertStringContainsString('1500', $mismatch['message']); // sumBase
        $this->assertStringContainsString('1815', $mismatch['message']); // sumWithVat = 1500 * 1.21
    }

    public function testTotalsBaseSumWithinToleranceProducesNoWarning(): void
    {
        // Doc without VAT — declared total matches the sum of base prices.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00],
            ],
            'totals' => ['totalAmount' => 1000.005],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsWithVatPerRowProducesNoWarning(): void
    {
        // Typical VAT invoice: AI emits row.totalPrice (base only) and
        // row.vat.pct. Declared totalAmount is with VAT. Variant (2) hits.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1815.00], // 1500 * 1.21
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsByVatRecapProducesNoWarning(): void
    {
        // vatRecap.total breakdown is the authoritative variant. Even when
        // row.totalPrice would not add up, vatRecap matching suppresses the
        // warning.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 800.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 200.00, 'vat' => ['pct' => 15]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 800.00, 'tax' => 168.00, 'total' => 968.00],
                ['vatPct' => 15, 'base' => 200.00, 'tax' => 30.00, 'total' => 230.00],
            ],
            'totals' => ['totalAmount' => 1198.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsNoVatRowsProducesNoWarning(): void
    {
        // Cash receipt with no VAT info at all.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 250.00],
                ['totalPrice' => 100.00],
            ],
            'totals' => ['totalAmount' => 350.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsAtToleranceEdgeProducesNoWarning(): void
    {
        // Diff 0.005 < tolerance 0.01 — should not fire even with a
        // base-only sum hitting just under the boundary.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00],
            ],
            'totals' => ['totalAmount' => 1000.005],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testFullValidPayloadProducesNoErrors(): void
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $issues = $this->v->validate($payload);
        $errors = array_filter($issues, static fn($i) => $i['severity'] === 'error');
        $this->assertSame([], $errors, 'Happy fixture should produce no errors: ' . json_encode($errors));
    }

    public function testPartnerDocNumberWarningWhenConfirmingWithoutNumber(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 20],
            // docNumber omitted
        ]);
        $w = $this->findByCode($issues, 'partner_doc_number_missing');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);
    }

    public function testPartnerDocNumberNotChalliengedForDraft(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 10],
        ]);
        $this->assertNull($this->findByCode($issues, 'partner_doc_number_missing'));
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    private function findByPath(array $issues, string $path): ?array
    {
        foreach ($issues as $issue) {
            if ($issue['path'] === $path) return $issue;
        }
        return null;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    private function findByCode(array $issues, string $code): ?array
    {
        foreach ($issues as $issue) {
            if ($issue['code'] === $code) return $issue;
        }
        return null;
    }
}
