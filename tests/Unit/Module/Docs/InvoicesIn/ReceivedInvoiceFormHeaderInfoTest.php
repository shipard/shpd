<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesIn;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;

class ReceivedInvoiceFormHeaderInfoTest extends TestCase
{
    private function createForm(): ReceivedInvoiceForm
    {
        // Bez DB / config — testujeme jen větve, které data berou přímo z $data.
        // Fallback do DB (resolvePartnerName přes partner FK) je pokrytý tím,
        // že bez DB vrátí prázdný string → buildHeaderInfo vrátí null.
        return new ReceivedInvoiceForm('docs_core_heads');
    }

    public function testNoPartnerAndNoSnapshotReturnsNull(): void
    {
        $form = $this->createForm();

        // Nový rozpracovaný záznam: žádný snapshot, žádný partner FK, žádné DB.
        $this->assertNull($form->buildHeaderInfo([
            'doc_type' => 'invni',
        ]));
    }

    public function testPartnerFromSupplierSnapshotAsArray(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'doc_type' => 'invni',
            'doc_number' => '2024-0001',
            'supplier_snapshot' => [
                'name' => 'Beta Software, a.s.',
                'company_id' => '68253848',
            ],
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Beta Software, a.s.', $info->title);
        $this->assertSame(
            [
                ['label' => '',      'value' => 'Přijatá faktura'],
                ['label' => 'Číslo', 'value' => '2024-0001'],
            ],
            $info->info,
        );
        $this->assertSame('invoice-in', $info->icon);
        $this->assertSame([], $info->summary);
    }

    public function testPartnerFromSupplierSnapshotAsJsonString(): void
    {
        $form = $this->createForm();

        // Snapshot se v DB drží jako JSON string — ověřujeme, že to umíme
        // dekódovat (DocDocument::beforeSave ho buildí přes json_encode).
        $info = $form->buildHeaderInfo([
            'doc_type' => 'invni',
            'supplier_snapshot' => json_encode(['name' => 'Gama, s.r.o.']),
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Gama, s.r.o.', $info->title);
    }

    public function testDocNumberOmittedWhenEmpty(): void
    {
        $form = $this->createForm();

        // Nový záznam s vybraným partnerem (přes snapshot), ale ještě bez
        // přiděleného čísla dokladu — info[] má jen Typ.
        $info = $form->buildHeaderInfo([
            'doc_type' => 'invni',
            'supplier_snapshot' => ['name' => 'Delta, s.r.o.'],
            'doc_number' => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => '', 'value' => 'Přijatá faktura']],
            $info->info,
        );
    }

    public function testSummaryHiddenForZeroTotals(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'doc_type' => 'invni',
            'supplier_snapshot' => ['name' => 'Eta, s.r.o.'],
            'total_amount' => 0,
            'total_base'   => 0,
            'total_vat'    => 0,
            'doc_currency' => 'czk',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->summary);
    }

    public function testSummaryWithComputedTotals(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'doc_type' => 'invni',
            'doc_number' => '2024-0001',
            'supplier_snapshot' => ['name' => 'Beta Software, a.s.'],
            'total_base'   => 10000.00,
            'total_vat'    => 2100.00,
            'total_amount' => 12100.00,
            'doc_currency' => 'czk',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [
                ['label' => 'Bez DPH',    'value' => '10 000,00'],
                ['label' => 'DPH',        'value' => '2 100,00'],
                ['label' => 'Celkem CZK', 'value' => '12 100,00'],
            ],
            $info->summary,
        );
    }

    public function testSummaryCelkemOmitsCurrencyWhenMissing(): void
    {
        $form = $this->createForm();

        // Defenzivní — pokud doc_currency náhodou chybí, „Celkem" hodnota
        // zůstane jen číslo bez měny (nelháme uživateli).
        $info = $form->buildHeaderInfo([
            'doc_type' => 'invni',
            'supplier_snapshot' => ['name' => 'Zeta, s.r.o.'],
            'total_amount' => 500.00,
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            ['label' => 'Celkem', 'value' => '500,00'],
            $info->summary[2],
        );
    }
}
