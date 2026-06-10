<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\DocRowOperationRules;

class DocRowOperationRulesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function cfg(): array
    {
        return [
            'sale.services' => ['name' => 'Sale of services', 'docTypes' => ['invno' => ['order' => 100]]],
            'purchase.services' => ['name' => 'Purchase of services', 'docTypes' => ['invni' => ['order' => 200]]],
            'acc.entry' => ['name' => 'Accounting entry', 'docTypes' => [
                'invno' => ['order' => 900], 'invni' => ['order' => 900],
            ]],
        ];
    }

    public function testStandardRowWithValidOperationPasses(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'sale.services'];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'invno', $this->cfg()));
    }

    public function testStandardRowWithoutOperationFails(): void
    {
        $row = ['row_kind' => 1];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertCount(1, $errors);
        $this->assertSame('operation', $errors[0]['column']);
        $this->assertSame('required', $errors[0]['code']);
    }

    public function testUnknownOperationFails(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'stock.in'];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertSame('operation_unknown', $errors[0]['code']);
    }

    public function testOperationNotAllowedForDocTypeFails(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'purchase.services'];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertSame('operation_not_allowed', $errors[0]['code']);
    }

    public function testTextRowWithOperationFails(): void
    {
        $row = ['row_kind' => 0, 'operation' => 'sale.services'];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertSame('operation_on_text_row', $errors[0]['code']);
    }

    public function testTextRowWithoutOperationPasses(): void
    {
        $row = ['row_kind' => 0, 'operation' => null];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'invno', $this->cfg()));
    }

    public function testAccEntryWithoutItemFails(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'acc.entry'];
        $errors = DocRowOperationRules::validateRow($row, 'invni', $this->cfg());

        $this->assertSame('item', $errors[0]['column']);
        $this->assertSame('item_required_for_acc_entry', $errors[0]['code']);
    }

    public function testAccEntryWithItemPasses(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'acc.entry', 'item' => 42];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'invni', $this->cfg()));
    }
}
