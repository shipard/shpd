<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesOut;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;

class IssuedInvoiceFormHeaderInfoTest extends TestCase
{
    private function createForm(): IssuedInvoiceForm
    {
        return new IssuedInvoiceForm('docs_core_heads');
    }

    public function testNoPartnerAndNoSnapshotReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'doc_type' => 'invno',
        ]));
    }

    public function testReadsFromCustomerSnapshotNotSupplier(): void
    {
        $form = $this->createForm();

        // FVB se musí podívat do customer_snapshot (odběratel),
        // NE do supplier_snapshot. Tady mám oba — pokud by se forma
        // dívala do supplier_snapshot, vrátila by "Špatný partner".
        $info = $form->buildHeaderInfo([
            'doc_type' => 'invno',
            'doc_number' => '2024-0001',
            'customer_snapshot' => ['name' => 'Odběratel s.r.o.'],
            'supplier_snapshot' => ['name' => 'Špatný partner'],
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Odběratel s.r.o.', $info->title);
        $this->assertSame(
            [
                ['label' => '',      'value' => 'Vydaná faktura'],
                ['label' => 'Číslo', 'value' => '2024-0001'],
            ],
            $info->info,
        );
        $this->assertSame('invoice', $info->icon);
        $this->assertSame([], $info->summary);
    }

    public function testIgnoresSupplierSnapshotEvenIfPresent(): void
    {
        $form = $this->createForm();

        // Defenzivní: i kdyby v datech omylem byl supplier_snapshot
        // (např. po nějakém AI extraktoru), FVB ho ignoruje a vrátí null
        // pokud customer_snapshot není.
        $this->assertNull($form->buildHeaderInfo([
            'doc_type' => 'invno',
            'supplier_snapshot' => ['name' => 'Bad supplier ref'],
        ]));
    }

    public function testCustomerSnapshotAsJsonString(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'doc_type' => 'invno',
            'customer_snapshot' => json_encode(['name' => 'JSON Customer']),
        ]);

        $this->assertNotNull($info);
        $this->assertSame('JSON Customer', $info->title);
    }

    public function testSummaryWithComputedTotals(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'doc_type' => 'invno',
            'doc_number' => '2024-0001',
            'customer_snapshot' => ['name' => 'Odběratel s.r.o.'],
            'total_base'   => 50000.00,
            'total_vat'    => 10500.00,
            'total_amount' => 60500.00,
            'doc_currency' => 'czk',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [
                ['label' => 'Bez DPH',    'value' => '50 000,00'],
                ['label' => 'DPH',        'value' => '10 500,00'],
                ['label' => 'Celkem CZK', 'value' => '60 500,00'],
            ],
            $info->summary,
        );
    }

    public function testSummaryHiddenForZeroTotals(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'doc_type' => 'invno',
            'customer_snapshot' => ['name' => 'Odběratel s.r.o.'],
            'total_amount' => 0,
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->summary);
    }

    public function testDocNumberOmittedWhenEmpty(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'doc_type' => 'invno',
            'customer_snapshot' => ['name' => 'Odběratel s.r.o.'],
            'doc_number' => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => '', 'value' => 'Vydaná faktura']],
            $info->info,
        );
    }
}
