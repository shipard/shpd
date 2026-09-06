<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;

/**
 * Pokladna v hlavičce faktur (#59 D4): per-typ formuláře mají vlastní
 * buildHeaderTab, proto se chování ověřuje pro všechny tři třídy.
 */
class InvoiceFormsCashDeskTest extends TestCase
{
    /** @return list<array{0: DocsHeadsFormBase}> */
    public static function forms(): array
    {
        return [
            'generic' => [new DocsHeadsForm('docs_core_heads')],
            'invno'   => [new IssuedInvoiceForm('docs_core_heads')],
            'invni'   => [new ReceivedInvoiceForm('docs_core_heads')],
        ];
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== 'basic') {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                    }
                }
            }
        }
        return null;
    }

    #[DataProvider('forms')]
    public function testCashDeskFollowsPaymentMethod(DocsHeadsFormBase $form): void
    {
        $def = $form->buildFormDefinition([], true);
        $this->assertSame('reload', $this->findElement($def, 'payment_method')?->triggers);
        $cashDesk = $this->findElement($def, 'cash_desk');
        $this->assertNotNull($cashDesk);
        $this->assertSame('lookup', $cashDesk->type);
        $this->assertTrue($cashDesk->hidden, 'převodem → pokladna skrytá');

        $def = $form->buildFormDefinition(['payment_method' => 0, 'doc_currency' => 'eur'], true);
        $cashDesk = $this->findElement($def, 'cash_desk');
        $this->assertFalse($cashDesk->hidden, 'hotovost → pokladna viditelná');
        $this->assertStringContainsString('EUR', (string) $cashDesk->hint);

        $def = $form->buildFormDefinition(['payment_method' => 0, 'cash_desk' => 7], true);
        $this->assertNull($this->findElement($def, 'cash_desk')->hint);
    }
}
