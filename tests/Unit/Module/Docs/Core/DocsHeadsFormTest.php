<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormTab;
use Shipard\Module\Docs\Core\DocsHeadsForm;

class DocsHeadsFormTest extends TestCase
{
    private function createForm(): DocsHeadsForm
    {
        return new DocsHeadsForm('docs_core_heads');
    }

    /** @return string[] */
    private function tabIds(FormDefinition $def): array
    {
        return array_map(static fn(FormTab $tab) => $tab->id, $def->tabs);
    }

    private function findElement(FormDefinition $def, string $tabId, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== $tabId) {
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

    private function findTab(FormDefinition $def, string $tabId): ?FormTab
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id === $tabId) {
                return $tab;
            }
        }
        return null;
    }

    /** Find the first element of a given type in a tab (e.g. 'html'). */
    private function findElementByType(FormDefinition $def, string $tabId, string $type): ?FormElement
    {
        $tab = $this->findTab($def, $tabId);
        if ($tab === null) {
            return null;
        }
        foreach ($tab->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->type === $type) {
                        return $el;
                    }
                }
            }
        }
        return null;
    }

    // ── Tab presence ─────────────────────────────────────────────────────────

    public function testNewDocumentHasBaseTabsWithoutSnapshots(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $this->assertSame(
            ['basic', 'rows', 'recap', 'notes', 'attachments'],
            $this->tabIds($def),
        );
    }

    public function testSupplierSnapshotAddsSnapshotsTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => ['name' => 'ACME s.r.o.'],
        ], false);

        $this->assertContains('snapshots', $this->tabIds($def));
    }

    public function testCustomerSnapshotAloneAlsoAddsSnapshotsTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'customer_snapshot' => ['name' => 'Zákazník s.r.o.'],
        ], false);

        $this->assertContains('snapshots', $this->tabIds($def));
    }

    public function testEmptySnapshotArrayDoesNotAddTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => [],
            'customer_snapshot' => null,
        ], false);

        $this->assertNotContains('snapshots', $this->tabIds($def));
    }

    public function testFormIsFullSizeAndTitled(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $this->assertTrue($def->fullSize);
        $this->assertSame('Doklad', $def->title);
        $this->assertSame('Nový doklad', $def->titleNew);
        $this->assertSame('docs_core_heads', $def->table);
    }

    // ── VAT mode visibility ──────────────────────────────────────────────────

    public function testVatModeZeroHidesVatFields(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['vat_mode' => 0], true);

        $hiddenWhenNoVat = ['vat_calc_source', 'vat_place', 'vat_registration',
            'vat_duzp', 'vat_dppd', 'vat_rounding_mode'];

        foreach ($hiddenWhenNoVat as $col) {
            $el = $this->findElement($def, 'basic', $col);
            $this->assertNotNull($el, "Column {$col} should exist");
            $this->assertTrue($el->hidden, "Column {$col} should be hidden when vat_mode=0");
        }
    }

    public function testVatModeOneShowsVatFields(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['vat_mode' => 1], true);

        $visibleWhenVat = ['vat_calc_source', 'vat_place', 'vat_registration',
            'vat_duzp', 'vat_dppd', 'vat_rounding_mode'];

        foreach ($visibleWhenVat as $col) {
            $el = $this->findElement($def, 'basic', $col);
            $this->assertNotNull($el);
            $this->assertFalse($el->hidden, "Column {$col} should be visible when vat_mode=1");
        }
    }

    // ── Foreign currency / exchange_rate visibility ──────────────────────────

    public function testHomeCurrencyMatchesDocCurrencyHidesExchangeRate(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'czk',
            'home_currency' => 'czk',
        ], false);

        $el = $this->findElement($def, 'basic', 'exchange_rate');
        $this->assertNotNull($el);
        $this->assertTrue($el->hidden);
    }

    public function testForeignCurrencyShowsExchangeRate(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'eur',
            'home_currency' => 'czk',
        ], false);

        $el = $this->findElement($def, 'basic', 'exchange_rate');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden);
    }

    // ── Identity tab readonly ────────────────────────────────────────────────

    public function testNumberSeriesEditableForNewDocument(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $el = $this->findElement($def, 'basic', 'number_series');
        $this->assertNotNull($el);
        $this->assertFalse($el->readOnly);
    }

    public function testNumberSeriesReadOnlyForExistingDocument(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['id' => 7], false);

        $el = $this->findElement($def, 'basic', 'number_series');
        $this->assertNotNull($el);
        $this->assertTrue($el->readOnly);
    }

    public function testDocNumberAlwaysReadOnly(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $el = $this->findElement($def, 'basic', 'doc_number');
        $this->assertNotNull($el);
        $this->assertTrue($el->readOnly);
    }

    // ── Recalculate (no DB needed for issue_date branch) ─────────────────────

    public function testIssueDateRecalcPropagatesDefaults(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('issue_date', [
            'issue_date' => '2026-05-07',
        ]);

        $this->assertSame('2026-05-07', $result->data['accounting_date']);
        $this->assertSame('2026-05-07', $result->data['vat_duzp']);
    }

    public function testIssueDateDoesNotOverwriteFilledAccountingDate(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('issue_date', [
            'issue_date'      => '2026-05-07',
            'accounting_date' => '2026-04-30',
        ]);

        $this->assertSame('2026-04-30', $result->data['accounting_date']);
    }

    // ── Triggers on user-driven reload selects ───────────────────────────────

    public function testCriticalSelectsTriggerReload(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        foreach (['partner', 'vat_mode', 'vat_place', 'vat_registration',
                  'doc_currency', 'issue_date'] as $col) {
            $el = $this->findElement($def, 'basic', $col);
            $this->assertNotNull($el, "Column {$col} should exist");
            $this->assertSame('reload', $el->triggers,
                "Column {$col} should trigger reload");
        }
    }

    // ── Partner lookups ──────────────────────────────────────────────────────

    public function testPartnerIsLookupAgainstPersons(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition([], true),
            'basic',
            'partner',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame('base_persons_persons', $el->lookup['table']);
        $this->assertNull($el->lookup['filter']);
        $this->assertSame('reload', $el->triggers);
    }

    public function testPartnerAddressLookupWithoutPartnerIsReadOnly(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition([], true),
            'basic',
            'partner_address',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame('base_persons_addresses', $el->lookup['table']);
        $this->assertNull($el->lookup['filter']);
        $this->assertTrue($el->readOnly);
    }

    public function testPartnerAddressLookupWithPartnerHasFilter(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition(['partner' => 42], false),
            'basic',
            'partner_address',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame(['person' => 42], $el->lookup['filter']);
        $this->assertFalse($el->readOnly);
    }

    public function testPartnerBankLookupWithPartnerHasFilter(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition(['partner' => 42], false),
            'basic',
            'partner_bank',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame('base_persons_bank_accounts', $el->lookup['table']);
        $this->assertSame(['person' => 42], $el->lookup['filter']);
    }

    // ── Subtable in rows tab ─────────────────────────────────────────────────

    public function testRowsTabHoldsSubtable(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $rowsTab = $this->findTab($def, 'rows');
        $this->assertNotNull($rowsTab);
        $this->assertSame('subtable', $rowsTab->type);
        $this->assertSame('docs_core_rows', $rowsTab->subtable['table']);
        $this->assertSame('doc_head', $rowsTab->subtable['foreignKey']);
        $this->assertSame('docs.core.rows', $rowsTab->subtable['formId']);
    }

    // ── Recap tab always present and has html ────────────────────────────────

    public function testRecapTabHasHtmlElement(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $html = $this->findElementByType($def, 'recap', 'html');
        $this->assertNotNull($html);
    }

    public function testRecapWithoutRowsShowsEmptyState(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $html = $this->findElementByType($def, 'recap', 'html');
        $this->assertNotNull($html);
        $this->assertStringContainsString('zatím nemá rekapitulaci', (string) $html->content);
    }

    public function testRecapWithVatRecapRendersTable(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'czk',
            'home_currency' => 'czk',
            'total_base'    => 1000,
            'total_vat'     => 210,
            'total_amount'  => 1210,
            'vatRecap'      => [
                [
                    'vat_code' => 'std',
                    'vat_pct'  => 21,
                    'base'     => 1000,
                    'tax'      => 210,
                    'total'    => 1210,
                    'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
                    'is_reverse_pair' => 0,
                ],
            ],
        ], false);

        $html = (string) $this->findElementByType($def, 'recap', 'html')->content;
        $this->assertStringContainsString('<table class="vat-recap">', $html);
        $this->assertStringContainsString('1 000,00', $html);
        $this->assertStringContainsString('1 210,00', $html);
    }

    public function testReverseChargePairGetsGrayedTaxColumn(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'czk',
            'home_currency' => 'czk',
            'vatRecap'      => [
                [
                    'vat_code' => 'rcv',
                    'vat_pct'  => 21,
                    'base'     => 1000,
                    'tax'      => 210,
                    'total'    => 1210,
                    'sum_base' => 1, 'sum_tax' => 0, 'sum_total' => 0,
                    'is_reverse_pair' => 1,
                ],
            ],
        ], false);

        $html = (string) $this->findElementByType($def, 'recap', 'html')->content;
        $this->assertStringContainsString('reverse-pair', $html);
        $this->assertStringContainsString('grayed', $html);
    }

    public function testForeignCurrencyRecapRendersTwoRows(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'eur',
            'home_currency' => 'czk',
            'exchange_rate' => 24.5,
            'total_base'        => 100,
            'total_vat'         => 21,
            'total_amount'      => 121,
            'total_base_dom'    => 2450,
            'total_vat_dom'     => 514.50,
            'total_amount_dom'  => 2964.50,
            'vatRecap'      => [
                [
                    'vat_code' => 'std',
                    'vat_pct'  => 21,
                    'base'     => 100,    'tax' => 21,    'total' => 121,
                    'base_dom' => 2450,   'tax_dom' => 514.50, 'total_dom' => 2964.50,
                    'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
                    'is_reverse_pair' => 0,
                ],
            ],
        ], false);

        $html = (string) $this->findElementByType($def, 'recap', 'html')->content;
        $this->assertStringContainsString('EUR', $html);
        $this->assertStringContainsString('CZK', $html);
        $this->assertStringContainsString('rowspan="2"', $html);
    }

    // ── Snapshots tab content ───────────────────────────────────────────────

    public function testSnapshotsTabRendersBothBlocks(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => [
                'name'       => 'Naše firma s.r.o.',
                'company_id' => '12345678',
                'tax_id'     => 'CZ12345678',
            ],
            'customer_snapshot' => [
                'name'       => 'Zákazník a.s.',
                'company_id' => '87654321',
            ],
        ], false);

        $html = (string) $this->findElementByType($def, 'snapshots', 'html')->content;
        $this->assertStringContainsString('Naše firma s.r.o.', $html);
        $this->assertStringContainsString('Zákazník a.s.', $html);
        $this->assertStringContainsString('IČO: 12345678', $html);
        $this->assertStringContainsString('Dodavatel', $html);
        $this->assertStringContainsString('Odběratel', $html);
    }

    public function testSnapshotJsonStringIsDecoded(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => json_encode(['name' => 'JSON Supplier']),
        ], false);

        $html = (string) $this->findElementByType($def, 'snapshots', 'html')->content;
        $this->assertStringContainsString('JSON Supplier', $html);
    }

    public function testSnapshotEscapesHtml(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => ['name' => '<script>x</script>'],
        ], false);

        $html = (string) $this->findElementByType($def, 'snapshots', 'html')->content;
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
