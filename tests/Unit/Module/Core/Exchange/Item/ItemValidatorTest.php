<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Item;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Item\ItemValidator;

class ItemValidatorTest extends TestCase
{
    private ItemValidator $v;

    protected function setUp(): void
    {
        $this->v = new ItemValidator();
    }

    // ── Happy path ────────────────────────────────────────────────────────

    public function testMinimalValidPayloadProducesNoIssues(): void
    {
        $issues = $this->v->validate([
            'name' => 'Konzultace IT',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertSame([], $issues);
    }

    public function testRichValidPayloadProducesNoIssues(): void
    {
        $issues = $this->v->validate([
            'code' => 'K-001',
            'name' => 'Konzultace IT',
            'unit' => 'h',
            'kind' => ['code' => 'service', 'name' => 'Konzultace', 'itemType' => 0],
            'salesPriceNoVat' => 1000.0,
            'sku' => 'CONSULT-IT',
            'ean' => '1234567890123',
            'applyOptions' => ['targetDocState' => 40],
        ]);
        $this->assertSame([], $issues);
    }

    // ── Required header fields ────────────────────────────────────────────

    public function testMissingNameProducesRequiredError(): void
    {
        $issues = $this->v->validate([
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $issue = $this->findByPath($issues, 'name');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('required', $issue['code']);
    }

    public function testWhitespaceOnlyNameIsTreatedAsEmpty(): void
    {
        $issues = $this->v->validate([
            'name' => '   ',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertNotNull($this->findByPath($issues, 'name'));
    }

    public function testMissingUnitProducesRequiredError(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'kind' => ['code' => 'service'],
        ]);
        $issue = $this->findByPath($issues, 'unit');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('required', $issue['code']);
    }

    // ── kind hints ────────────────────────────────────────────────────────

    public function testKindMissingEntirelyProducesError(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
        ]);
        $issue = $this->findByPath($issues, 'kind');
        $this->assertNotNull($issue);
        $this->assertSame('kind_required', $issue['code']);
    }

    public function testKindWithAllThreeHintsEmptyProducesError(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => '', 'name' => null, 'itemType' => null],
        ]);
        $issue = $this->findByPath($issues, 'kind');
        $this->assertNotNull($issue);
        $this->assertSame('kind_required', $issue['code']);
    }

    public function testKindWithOnlyCodeIsValid(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertNull($this->findByPath($issues, 'kind'));
    }

    public function testKindWithOnlyNameIsValid(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['name' => 'Konzultace'],
        ]);
        $this->assertNull($this->findByPath($issues, 'kind'));
    }

    public function testKindWithOnlyItemTypeIsValid(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['itemType' => 0],
        ]);
        $this->assertNull($this->findByPath($issues, 'kind'));
    }

    public function testKindWithItemTypeZeroCountsAsHint(): void
    {
        // 0 is a valid itemType (Service); make sure the truthiness check
        // doesn't accidentally drop it.
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['itemType' => 0],
        ]);
        $this->assertNull($this->findByPath($issues, 'kind'));
    }

    // ── Pricing ───────────────────────────────────────────────────────────

    public function testNegativeSalesPriceProducesError(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
            'salesPriceNoVat' => -10.0,
        ]);
        $issue = $this->findByPath($issues, 'salesPriceNoVat');
        $this->assertNotNull($issue);
        $this->assertSame('price_negative', $issue['code']);
    }

    public function testZeroSalesPriceIsValid(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
            'salesPriceNoVat' => 0,
        ]);
        $this->assertNull($this->findByPath($issues, 'salesPriceNoVat'));
    }

    public function testNullSalesPriceIsValid(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
            'salesPriceNoVat' => null,
        ]);
        $this->assertNull($this->findByPath($issues, 'salesPriceNoVat'));
    }

    public function testMissingSalesPriceKeyIsValid(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertNull($this->findByPath($issues, 'salesPriceNoVat'));
    }

    // ── code pattern ──────────────────────────────────────────────────────

    public function testCodeWithWhitespaceIsRejected(): void
    {
        $issues = $this->v->validate([
            'code' => 'K 001',
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $issue = $this->findByPath($issues, 'code');
        $this->assertNotNull($issue);
        $this->assertSame('code_invalid', $issue['code']);
    }

    public function testCodeOver25CharsIsRejected(): void
    {
        $issues = $this->v->validate([
            'code' => str_repeat('A', 26),
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $issue = $this->findByPath($issues, 'code');
        $this->assertNotNull($issue);
        $this->assertSame('code_invalid', $issue['code']);
    }

    public function testCode25CharsIsAccepted(): void
    {
        $issues = $this->v->validate([
            'code' => str_repeat('A', 25),
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertNull($this->findByPath($issues, 'code'));
    }

    public function testNullCodeIsAccepted(): void
    {
        $issues = $this->v->validate([
            'code' => null,
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertNull($this->findByPath($issues, 'code'));
    }

    public function testEmptyCodeIsAccepted(): void
    {
        $issues = $this->v->validate([
            'code' => '',
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertNull($this->findByPath($issues, 'code'));
    }

    // ── Secondary keys ────────────────────────────────────────────────────

    public function testSkuTooLongIsRejected(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
            'sku' => str_repeat('A', 51),
        ]);
        $issue = $this->findByPath($issues, 'sku');
        $this->assertNotNull($issue);
        $this->assertSame('sku_too_long', $issue['code']);
    }

    public function testEanTooLongIsRejected(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
            'ean' => str_repeat('1', 21),
        ]);
        $issue = $this->findByPath($issues, 'ean');
        $this->assertNotNull($issue);
        $this->assertSame('ean_too_long', $issue['code']);
    }

    // ── applyOptions.targetDocState ───────────────────────────────────────

    public function testInvalidTargetDocStateIsRejected(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
            'applyOptions' => ['targetDocState' => 99],
        ]);
        $issue = $this->findByPath($issues, 'applyOptions.targetDocState');
        $this->assertNotNull($issue);
        $this->assertSame('target_doc_state_invalid', $issue['code']);
    }

    public function testNullTargetDocStateIsAccepted(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
            'applyOptions' => ['targetDocState' => null],
        ]);
        $this->assertNull($this->findByPath($issues, 'applyOptions.targetDocState'));
    }

    public function testMissingApplyOptionsIsAccepted(): void
    {
        $issues = $this->v->validate([
            'name' => 'X',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);
        $this->assertNull($this->findByPath($issues, 'applyOptions.targetDocState'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return ?array{severity: string, path: string, code: string, message: string}
     */
    private function findByPath(array $issues, string $path): ?array
    {
        foreach ($issues as $i) {
            if ($i['path'] === $path) return $i;
        }
        return null;
    }
}
