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

    public function testTotalsMismatchProducesWarning(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['computed' => ['vatTotal' => 1000.00]],
                ['computed' => ['vatTotal' => 500.00]],
            ],
            'totals' => ['totalAmount' => 1499.50],
        ]);
        $mismatch = $this->findByCode($issues, 'totals_mismatch');
        $this->assertNotNull($mismatch);
        $this->assertSame('warning', $mismatch['severity']);
        $this->assertStringContainsString('1500', $mismatch['message']);
    }

    public function testTotalsWithinToleranceProducesNoWarning(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['computed' => ['vatTotal' => 1000.00]],
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
