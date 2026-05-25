<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\DocsHeadsFormBase;

/**
 * Editační formulář pro Faktury přijaté (FPB) — `doc_type = 'invni'`.
 *
 * Dědí veškerou logiku z DocsHeadsFormBase. V MVP přepisuje pouze titulky;
 * slouží jako rozšiřovací bod pro FPB-specifické změny formuláře
 * (např. schvalovací workflow, vazba na příchozí poštu, AI extrakce,
 * DPH-PDP-specifické přepínače, skrytí polí relevantních jen pro FVB atd.).
 */
class ReceivedInvoiceForm extends DocsHeadsFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura přijatá';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura přijatá';
    }
}
