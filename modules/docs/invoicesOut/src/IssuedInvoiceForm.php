<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\DocsHeadsFormBase;

/**
 * Editační formulář pro Faktury vydané (FVB) — `doc_type = 'invno'`.
 *
 * Dědí veškerou logiku z DocsHeadsFormBase. V MVP přepisuje pouze titulky;
 * slouží jako rozšiřovací bod pro FVB-specifické změny formuláře
 * (např. extra sekce pro splátkový kalendář, výzva k úhradě, AI checks,
 * skrytí polí relevantních jen pro FPB atd.).
 */
class IssuedInvoiceForm extends DocsHeadsFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura vydaná';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura vydaná';
    }
}
